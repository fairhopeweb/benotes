<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Intervention\Image\Facades\Image;

use App\Post;
use App\Collection;

use ColorThief\ColorThief;

class PostController extends Controller
{

    public function index(Request $request)
    {

        $this->validate($request, [
            'collection_id' => 'integer|nullable',
            'is_uncategorized' => 'boolean|nullable',
            'limit' => 'integer|nullable'
        ]);
        
        $request->is_uncategorized = filter_var($request->is_uncategorized, FILTER_VALIDATE_BOOLEAN);

        if (Auth::guard('api')->check()) {
            if (isset($request->collection_id)
                || (isset($request->is_uncategorized) && $request->is_uncategorized === true)) {
                $collection_id = Collection::getCollectionId($request->collection_id, 
                    $request->is_uncategorized);
                $posts = Post::where([
                    ['collection_id', '=', $collection_id],
                    ['user_id', '=', Auth::user()->id]
                ]);
            } else {
                $posts = Post::where('user_id', Auth::user()->id);
            }
        } else if (Auth::guard('share')->check()) {
            $share = Auth::guard('share')->user();
            $posts = Post::where([
                'collection_id' => $share->collection_id,
                'user_id' => $share->created_by
            ]);
        } else {
            return response()->json('', 400);
        }

        if (isset($request->limit)) {
            $posts = $posts->limit($request->limit);
        }
        $posts = $posts->orderBy('order', 'desc')->get();

        return response()->json(['data' => $posts], 200);
    }

    public function show(int $id) 
    {
        $post = Post::find($id);
        if ($post === null) {
            return response()->json('Post does not exist', 404);
        }
        $this->authorize('view', $post);
        return response()->json(['data' => $post], 200);
    }

    public function store(Request $request)
    {
        $validatedData = $this->validate($request, [
            'title' => 'string|nullable',
            'content' => 'required|string',
            'collection_id' => 'integer|nullable'
        ]);

        if (isset($request->collection_id)) {
            $collection = Collection::findOrFail($request->collection_id);
            if (Auth::user()->id !== $collection->user_id) {
                return response()->json('Not authorized', 403);
            }
        }

        $validatedData['content'] = $this->sanitize($validatedData['content']);

        $info = $this->computePostData($request->title, $request->content);

        $attributes = array_merge($validatedData, $info);
        $attributes['user_id'] = Auth::user()->id;
        $attributes['order'] = Post::where('collection_id', Collection::getCollectionId($request->collection_id))
            ->max('order') + 1;
        
        $post = Post::create($attributes);
        if ($info['type'] === Post::POST_TYPE_LINK) {
            $this->saveImage($info['image_path'], $post);
        }

        return response()->json(['data' => $post], 201);
    }

    public function update(Request $request, $id)
    {

        $validatedData = $this->validate($request, [
            'title' => 'string|nullable',
            'content' => 'string|nullable',
            'collection_id' => 'integer|nullable',
            'is_uncategorized' => 'boolean|nullable',
            'order' => 'integer|nullable'
        ]);

        $post = Post::find($id);
        if (!$post) {
            return response()->json('Post not found.', 404);
        }

        $this->authorize('update', $post);

        $request->is_uncategorized = filter_var($request->is_uncategorized, FILTER_VALIDATE_BOOLEAN);

        if (empty($request->collection_id) && $request->is_uncategorized === false) {
            // request contains no knowledge about a collection
            $validatedData['collection_id'] = $post->collection_id;
        } else {
            $validatedData['collection_id'] = Collection::getCollectionId(
                $request->collection_id,
                $request->is_uncategorized
            );
        }

        if (!empty($validatedData['collection_id'])) {
            Collection::findOrFail($validatedData['collection_id']);
        }

        if (isset($validatedData['content'])) {
            $validatedData['content'] = $this->sanitize($validatedData['content']);
            $info = $this->computePostData($request->title, $validatedData['content']);
        } else {
            $info = array();
            $info['type'] = Post::getTypeFromString($post->type);
        }

        $newValues = array_merge($validatedData, $info);
        $newValues['user_id'] = Auth::user()->id;

        if ($post->collection_id !== $validatedData['collection_id']) {
            // post wants to have a different collection than before
            // compute order in new collection
            $newValues['order'] = Post::where('collection_id', $validatedData['collection_id'])
                ->max('order') + 1;
            // reorder old collection
            Post::where('collection_id', $post->collection_id)
                ->where('order', '>', $post->order)->decrement('order');
        } else if (isset($validatedData['order'])) {
            // post wants to only be positioned somewhere else 
            // staying in the same collection as before
            $newOrder = $validatedData['order'];
            $oldOrder = $post->order;
            if ($newOrder !== $oldOrder) {
                if ($newOrder > $oldOrder) {
                    Post::where('collection_id', $post->collection_id)
                        ->whereBetween('order', [$oldOrder + 1, $newOrder])->decrement('order');
                } else {
                    Post::where('collection_id', $post->collection_id)
                        ->whereBetween('order', [$newOrder, $oldOrder - 1])->increment('order');
                }
            }
        }

        $post->update($newValues); 

        if ($info['type'] === Post::POST_TYPE_LINK && isset($validatedData['content'])) {
            $this->saveImage($info['image_path'], $post);
        }

        return response()->json(['data' => $post], 200);
    }

    public function destroy(int $id)
    {

        $post = Post::find($id);
        
        if (!$post) {
            return response()->json('Post not found.', 404);
        }
        $this->authorize('delete', $post);

        Post::where('collection_id', $post->collection_id)
            ->where('order', '>', $post->order)
            ->where('deleted_at', null)
            ->decrement('order');

        $post->delete();

        return response()->json('', 204);

    }

    public function getUrlInfo(Request $request)
    {
        $this->validate($request, [
            'url' => 'url'
        ]);

        return response()->json($this->getInfo($request->url));
    }

    private function computePostData(string $title = null, string $content)
    {
        preg_match_all('/https?(:\/\/)((\w|-)+\.)+(\w+)(\S+)(?<!")/', $content, $matches);
        $matches = $matches[0];
        $info = null;
        if (count($matches) > 0) {
            $info = $this->getInfo($matches[0]);
        }

        if (!empty($title)) {
            unset($info['title']);
        }

        $stripped_content = strip_tags($content);
        if (empty($matches)) {
            $info['type'] = Post::POST_TYPE_TEXT;
        } else if (strlen($stripped_content) > strlen($matches[0])) { // contains more than just a link
            $info['type'] = Post::POST_TYPE_TEXT;
        } else {
            $info['type'] = Post::POST_TYPE_LINK;
        }

        return $info;
    }

    private function sanitize($str)
    {
        return strip_tags($str, '<a><strong><b><em><i><s><p><h1><h2><h3><h4><h5>' . 
            '<pre><br><hr><blockquote><ul><li><ol><code>');
    }

    private function getInfo($url)
    {
        $base_url = parse_url($url);
        $base_url = $base_url['scheme'] . '://' . $base_url['host'];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($ch, CURLOPT_AUTOREFERER, 1);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/80.0.3987.132 Safari/537.36');
        curl_setopt($ch, CURLOPT_URL, $url);
        $html = curl_exec($ch);
        curl_close($ch);

        $document = new \DOMDocument();
        @$document->loadHTML($html);
        $titles = $document->getElementsByTagName('title');
        if (count($titles) > 0) {
            $title = trim($titles->item(0)->nodeValue);
        } else {
            $title = $base_url;
        }
        $metas = $document->getElementsByTagName('meta');
        
        for ($i = 0; $i < $metas->length; $i++) {
            $meta = $metas->item($i);
            if ($meta->getAttribute('name') === 'description') {
                $description = $meta->getAttribute('content');
            } else if ($meta->getAttribute('name') === 'theme-color') {
                $color = $meta->getAttribute('content');
            } else if ($meta->getAttribute('property') === 'og:image') {
                $image_path = $meta->getAttribute('content');
                $base_image_url = parse_url($image_path);
                if ($base_image_url['path'] === $image_path) {
                    $image_path = $base_url.$image_path;
                }
            }
        }

        if (empty($color)) {
            $color = $this->getDominantColor($base_url);
        }

        return [
            'url' => $url,
            'base_url' => $base_url,
            'title' => substr($title, 0, 200),
            'description' => (empty($description)) ? null : $description,
            'color' => (empty($color)) ? null : $color,
            'image_path' => (empty($image_path)) ? null : $image_path,
        ];
    }

    private function getDominantColor($base_url)
    {
        $host = parse_url($base_url)['host'];
        $rgb = ColorThief::getColor('https://www.google.com/s2/favicons?domain=' . $host);
        $hex = sprintf("#%02x%02x%02x", $rgb[0], $rgb[1], $rgb[2]);
        return $hex;
    }

    private function saveImage($image_path, $post)
    {
        if (!empty($image_path)) {
            $image = Image::make($image_path);
            if ($image) {
                $image->fit(400, 210);
                $filename = 'thumbnail_' . $post->id . '.jpg';
                $image->save(storage_path().'/app/public/thumbnails/' . $filename, 100);
                $post->image_path = $filename;
                $post->save();
            }
        }
    }

}
