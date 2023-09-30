<?php

namespace Rakutentech\LaravelRequestDocs\Commands;

use Illuminate\Console\Command;
use Rakutentech\LaravelRequestDocs\LaravelRequestDocs;
use Rakutentech\LaravelRequestDocs\LaravelRequestDocsToOpenApi;

class ExportRequestDocsCommand extends Command
{
    private LaravelRequestDocs          $laravelRequestDocs;
    private LaravelRequestDocsToOpenApi $laravelRequestDocsToOpenApi;

    public function __construct(LaravelRequestDocs $laravelRequestDoc, LaravelRequestDocsToOpenApi $laravelRequestDocsToOpenApi)
    {
        parent::__construct();

        $this->laravelRequestDocs          = $laravelRequestDoc;
        $this->laravelRequestDocsToOpenApi = $laravelRequestDocsToOpenApi;
    }

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'laravel-request-docs:export
                            {path? : Export file location}
                            {--force : Whether to overwrite existing file}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate OpenAPI collection as json file';

    private string $exportFilePath;

    /**
     * Execute the console command.
     */
    public function handle()
    {
        if ($this->confirmFilePath()) {
            try {
                $excludedMethods = config('request-docs.open_api.exclude_http_methods', []);

                $excludedMethods = array_map(fn($item) => strtolower($item), $excludedMethods);

                $showGet    = !in_array('get', $excludedMethods);
                $showPost   = !in_array('post', $excludedMethods);
                $showPut    = !in_array('put', $excludedMethods);
                $showPatch  = !in_array('patch', $excludedMethods);
                $showDelete = !in_array('delete', $excludedMethods);
                $showHead   = !in_array('head', $excludedMethods);

                // Get a list of Doc with route and rules information.
                // If user defined `Route::match(['get', 'post'], 'uri', ...)`,
                // only a single Doc will be generated.
                $docs = $this->laravelRequestDocs->getDocs(
                    $showGet,
                    $showPost,
                    $showPut,
                    $showPatch,
                    $showDelete,
                    $showHead,
                );

                // Loop and split Doc by the `methods` property.
                // `Route::match([...n], 'uri', ...)` will generate n number of Doc.
                $docs = $this->laravelRequestDocs->splitByMethods($docs);
                $docs = $this->laravelRequestDocs->sortDocs($docs, 'default');
                $docs = $this->laravelRequestDocs->groupDocs($docs, 'default');

                $content = json_encode(
                    $this->laravelRequestDocsToOpenApi->openApi($docs->all())->toArray(),
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                );

                if (!$this->writeFile($content)) {
                    throw new \ErrorException("Failed to write on [{$this->exportFilePath}] file.");
                }
            } catch (\Exception $exception) {
                $this->error('Error : ' . $exception->getMessage());
                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }

    /**
     * @return bool
     */
    private function confirmFilePath(): bool
    {
        $path = $this->argument('path');

        if (!$path) {
            $path = config('request-docs.export_path', 'api.json');
        }

        $this->exportFilePath = base_path($path);

        $path = str_replace(base_path('/'), '', $this->exportFilePath);

        if (file_exists($this->exportFilePath)) {
            if (!$this->option('force')) {
                if ($this->confirm("File exists on [{$path}]. Overwrite?", false) == true) {
                    return true;
                }
                return false;
            }
        }

        return true;
    }

    /**
     * @param $content
     * @return false|int
     */
    private function writeFile($content)
    {
        $targetDirectory = dirname($this->exportFilePath);

        if (!is_dir($targetDirectory)) {
            mkdir($targetDirectory, 0755, true);
        }

        return file_put_contents($this->exportFilePath, $content);
    }
}
