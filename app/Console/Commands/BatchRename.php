<?php

namespace App\Console\Commands;

use Icewind\SMB\BasicAuth;
use Icewind\SMB\ServerFactory;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Intervention\Image\ImageManager;

class BatchRename extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'psd:rename';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Traverse specified directories and append dimensions to filename';

    private $exclusions;
    private $root;

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();

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

        // Set up params.
        $this->exclusions = ['original', 'thumbs'];
        $this->root = 'to_be_renamed';
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        // if ($this->confirm('Proceed with batch renaming?')):
            // Get top level directories.
            $top = $this->share->dir($this->root);

            // Traverse directories + process renaming.
            foreach ($top as $dir):
                $this->process($dir);
            endforeach;

            $this->newLine();
        // endif;
    }

    private function process($dir)
    {
        $this->info('******************************************************************');
        $this->info('Processing path ' . $dir->getPath() . '...');
        $this->info('******************************************************************');

        // Set current path and contents.
        $path = $dir->getPath();
        $contents = $this->share->dir($path);

        // Process directory.
        foreach ($contents as $item):
            // Skip if name contains exclusion.
            if (Str::contains(
                    Str::lower(
                        $item->getName()
                    ), $this->exclusions
                )
            ):
                $this->line('Skipping item: ' . $item->getPath() . '...');
                continue;
            endif;

            // Recusively handle when directory found.
            if ($item->isDirectory()):
                $this->info('Item ' . $item->getName() . ' is a directory, checking for files...');
                $this->process($item);
            // Download a copy of the file and create an ImageMagick object.
            // Extract dimensions and rename file.
            else: 
                $this->info('Found file ' . $item->getName() . ' - renaming...');

                try {
                    $this->share->get($item->getPath(), storage_path('renaming/temp') . '/' . $item->getName());
                } catch (\Throwable $th) {
                    $this->error('Error getting copy of file: ' . $th->getMessage() . ' - skipping...');
                    continue;
                }

                try {
                    $image = $this->image->make(
                                storage_path('renaming/temp/' . $item->getName())
                            );
                } catch (\Throwable $th) {
                    $this->error('Error creating file object: ' . $th->getMessage());
                    continue;
                }

                // Construct new file name.
                $name = Str::beforeLast($item->getName(), '.');
                $ext = Str::afterLast($item->getName(), '.');
                $fileName = $name . '_' . $image->width() . 'x' . $image->height() . '.' . $ext;

                // Set up the destination path.
                $pathSplit = Str::of($item->getPath())->explode('/');
                $dest = $pathSplit[0] . '/' . $pathSplit[1];

                // Rename file.
                try {
                    $this->share->rename($item->getPath(), $dest . '/' . $fileName, $item);
                } catch (\Throwable $th) {
                    $this->error('Error renaming file: ' . $th->getMessage());
                    continue;
                }
            endif;
        endforeach;
    }
}
