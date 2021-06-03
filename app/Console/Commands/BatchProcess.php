<?php

namespace App\Console\Commands;

use Icewind\SMB\BasicAuth;
use Icewind\SMB\ServerFactory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\ImageManager;

class BatchProcess extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'psd:batch';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process batch conversion of PSD files from SMB to local storage in selected format';

    private $image;
    private $formats;
    private $share;

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();

        // Default formats.
        $this->formats = [
            'jpg', 'png', 'gif', 'tif'
        ];

        // Init ImageMagick.
        $this->image = new ImageManager(['driver' => 'imagick']);

        // Set up SMB connection.
        $smbConfig = config()->get('filesystems.disks.smb');
        $factory = new ServerFactory();
        $auth = new BasicAuth(
            $smbConfig['user'], 
            $smbConfig['domain'], 
            $smbConfig['password']
        );
        $server = $factory->createServer($smbConfig['host'], $auth);
        $this->share = $server->getShare($smbConfig['share']);
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        // Set options.
        $format = $this->choice('Set file format to output', $this->formats, 0, null, false);
        $quality = $this->ask('Set quality (0 - 100)');

        // Ensure path exists.
        if (!File::exists(storage_path('conversions/input'))):
            $this->error('Sorry, path does not exist. Please check directory exists in storage/conversions path.');
            exit();
        endif;

        // Check quality is numeric and within boundaries.
        if (!is_numeric($quality) || $quality < 0 || $quality > 100):
            $this->error('Quality must be a number between 0 and 100');
            exit();
        endif;

        // Get confirmation to proceed.
        $this->info('All PSD files in the Conversion directory will be converted to ' . Str::upper($format) . '.');
        
        if ($this->confirm('Proceed with conversion?')):
            $files = $this->share->dir('Conversion');
            $this->info(count($files) . ' files will be converted. Starting conversion...');
            
            $this->newLine();
            $bar = $this->output->createProgressBar(count($files));
            
            foreach ($files as $file):
                // Skip insitu images.
                if (Str::of(Str::lower($file->getName()))->contains('insitu')):
                    Log::info('Skipping ' . $file->getName());
                    $bar->advance();
                    continue;
                endif;

                // Set output file name.
                $fileName = Str::of($file->getName())->beforeLast('.')->after('input') . '.' . $format;

                // Read file from share and save a copy to local storage.
                try {
                    $this->share->get($file->getPath(), storage_path('conversions/temp') . '/' . $file->getName());
                } catch (\Throwable $th) {
                    $this->error('Could not read file ' . $file->getName() . ' - skipping...');
                    $bar->advance();
                    continue;
                }

                // Create image object and encode in selected format.
                try {
                    $image = $this->image->make(
                                storage_path('conversions/temp/' . $file->getName())
                            )
                            ->encode($format, 100);
                } catch (\Throwable $th) {
                    $this->error('Was able to read file ' . $file->getName() . ', but could not convert - skipping...');
                    $this->newLine();
                    $bar->advance();
                    continue;
                }

                // Save to output directory.
                try {
                    $write = $this->share->write('Converted/' . $fileName);
                    fwrite($write, $image);
                    fclose($write);
                } catch (\Throwable $th) {
                    $this->error('Was able to convert file ' . $file->getName() . ', but could not save to SMB share - moving on...');
                    Storage::disk('conversions')->delete('/temp/' . $file->getName());
                    $this->newLine();
                    $bar->advance();
                    continue;
                }

                // Delete local copy.
                try {
                    Storage::disk('conversions')->delete('/temp/' . $file->getName());
                } catch (\Throwable $th) {
                    $this->info('Everything worked for ' . $file->getName() . ', but could not delete the temp file');
                    $this->newLine();
                    $bar->advance();
                    continue;
                }

                // Success, move on to the next file.
                Log::info('Successfully converted ' . $file->getName());
                $bar->advance();
            endforeach;

            $bar->finish();
        endif;

        $this->newLine();
        $this->info('Conversion complete.');
    }
}
