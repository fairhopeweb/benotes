<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Hash;
use App\User;

class InstallCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'install';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Install the application';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $this->line('Initiate installation...');
        $bar = $this->output->createProgressBar(3);
        $bar->start();
        
        // jwt secret
        $this->call('jwt:secret');
        $bar->advance();

        // database migration
        $this->call('migrate');
        $bar->advance();
        $this->line(PHP_EOL);

        // database seeding 
        $this->info('Create your Admin account:');
        $username = $this->ask('Username', 'Admin');
        $email = $this->ask('Email');
        $password = $this->secret('Password');

        $validator = Validator::make([
            'username' => $username,
            'email' => $email
        ], [
            'username' => 'string',
            'email' => 'email',
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }
            return;
        }

        $user = new User;
        $user->name = $username;
        $user->email = $email;
        $user->password = Hash::make($password);
        $user->permission = User::ADMIN;
        $user->save();

        (new \App\Post())->seedIntroData($user->id);

        $bar->finish();
        $this->line(PHP_EOL);
        $this->info('Installation complete.');
    }

}
