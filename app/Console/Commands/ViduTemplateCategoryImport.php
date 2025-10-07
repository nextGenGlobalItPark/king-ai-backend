<?php

namespace App\Console\Commands;

use App\Models\DataSource;
use App\Models\TemplateCategory;
use App\Models\TemplateCategoryDataSource;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ViduTemplateCategoryImport extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:vidu-template-category-import';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $client = new Client();

        try {
            $response = $client->get('https://api.vidu.com/scene/v1/template-categories');

            // Log the full response body
            Log::info('Vidu API Response: ' . $response->getBody()->getContents());

            // Rewind the response body to parse it as JSON
            $response->getBody()->rewind();
            $data = json_decode($response->getBody()->getContents(), true);
            $dataSourceId = DataSource::query()->whereLike('name','vidu')->first()->id;

            if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
                if (isset($data['categories']) && is_array($data['categories'])) {
                    foreach ($data['categories'] as $category) {
                        // Store except count, with source_id (using API id) and source as 'vidu'
                        $templateCategory = TemplateCategory::firstOrCreate(
                            ['template_category_name' => $category['name']]
                        );
                        $templateCategoryId = $templateCategory->id;
                        TemplateCategoryDataSource::firstOrCreate(
                            [
                                'template_category_id' => $templateCategoryId,
                                'datasource_id' => $dataSourceId,
                                'source_db_id' => $category['id']
                            ],
                        );

                    }
                    $this->info('Categories fetched and stored successfully.');
                    return 0; // Success
                } else {
                    Log::warning('Vidu API response does not contain expected categories array.');
                    $this->warn('No categories found in the API response.');
                    return 1; // Partial failure
                }
            } else {
                Log::error('Vidu API request failed: Status ' . $response->getStatusCode() . ' - ' . $response->getBody()->getContents());
                $this->error('Failed to fetch categories from Vidu API. Check logs for details.');
                return 1; // Failure
            }
        } catch (RequestException $e) {
            Log::error('Guzzle HTTP request failed: ' . $e->getMessage());
            if ($e->hasResponse()) {
                Log::error('Response: ' . $e->getResponse()->getBody()->getContents());
            }
            $this->error('Failed to fetch categories: ' . $e->getMessage());
            return 1; // Failure
        } catch (\Exception $e) {
            Log::error('Unexpected error during Vidu API fetch: ' . $e->getMessage());
            $this->error('An unexpected error occurred: ' . $e->getMessage());
            return 1; // Failure
        }
    }
}
