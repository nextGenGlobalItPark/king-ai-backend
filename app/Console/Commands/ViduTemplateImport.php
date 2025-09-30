<?php

namespace App\Console\Commands;

use App\Models\DataSource;
use App\Models\Template;
use App\Models\TemplateCategory;
use App\Models\TemplateCategoryDataSource;
use App\Models\TemplateContent;
use App\Models\ViduTemplate;
use GuzzleHttp\Client;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class ViduTemplateImport extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:vidu-template-import';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fetch categories from the Vidu API and store them in the database';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $client = new Client();

        $page_token = '';
        do {
            $url = 'https://api.vidu.com/scene/v1/template-scenes?pager.page_token=' . $page_token . '&pager.pagesz=20';

            try {
                $dataSource = DataSource::query()->whereLike('name', 'vidu')->first();
                $dataSourceId = $dataSource->id;

                $response = $client->get($url);
                $status = $response->getStatusCode();
                $body = $response->getBody()->getContents();

                if ($status !== Response::HTTP_OK) {
                    $this->error('Failed to fetch data from API.');
                    break;
                }

                $data = json_decode($body, true);

                foreach ($data['template_list'] ?? [] as $viduTemplate) {
                    DB::beginTransaction();
                    if (ViduTemplate::where('resource_id', $viduTemplate['id'])->exists()) {
                        continue;
                    }
                    $template = Template::create([
                        'name' => $viduTemplate['name'],
                        'template_category_id' => $viduTemplate['category_id'],
                        'credit_cost' => $viduTemplate['detail']['credit_cost'],
                        'created_by' => 1
                    ]);
                    $category = TemplateCategory::firstOrCreate(
                        [
                            'template_category_name' => $viduTemplate['category_name']
                        ],
                        [
                            'template_category_slug' => Str::slug($viduTemplate['category_name']),
                            'created_by' => 1,
                        ]
                    );

                    TemplateCategoryDataSource::firstOrCreate(
                        [
                            'template_category_id' => $category->id,
                            'datasource_id' => $dataSourceId

                        ],
                        [
                            'source_db_id' => $viduTemplate['category_id'],
                            'created_by' => 1,
                        ]
                    );

                    $cover_path = $this->downloadFile($viduTemplate['cover_url'] ?? null, 'images');
                    if($cover_path) {
                        TemplateContent::create(
                            [
                                'template_id' => $template->id,
                                'template_content_type' => 'cover',
                                'template_content' => $cover_path,
                                'created_by' => 1
                            ]
                        );
                    }

                    $video_path = $this->downloadFile($viduTemplate['video_url'] ?? null, 'videos');
                    if($video_path) {
                        TemplateContent::create(
                            [
                                'template_id' => $template->id,
                                'template_content_type' => 'video',
                                'template_content' => $video_path,
                                'created_by' => 1
                            ]
                        );
                    }

                    $input = $viduTemplate['input_instruction'] ?? [];
                    $local_images = [];
                    foreach ($input['image_url'] ?? [] as $img_url) {
                        $img_url = $this->downloadFile($img_url, 'images');
                        TemplateContent::create(
                            [
                                'template_id' => $template->id,
                                'template_content_type' => 'image',
                                'template_content' => $img_url,
                                'created_by' => 1
                            ]
                        );
                        $local_images[] = $this->downloadFile($img_url, 'images');
                    }

                    $api_ex = $viduTemplate['api_example'] ?? [];
                    $request_str = $api_ex['request'] ?? '';
                    $new_request = $request_str;

                    if (preg_match("/-d '\n\s*({.*})\n\s*'/s", $request_str, $matches)) {
                        $json_str = $matches[1];
                        $req_data = json_decode($json_str, true);
                        if (isset($req_data['images'])) {
                            $new_images = [];
                            foreach ($req_data['images'] as $img) {
                                $new_images[] = $this->downloadFile($img, 'images');
                            }
                            $req_data['images'] = $new_images;
                            $new_json = json_encode($req_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                            $new_request = str_replace($json_str, $new_json, $request_str);
                        }
                    }


                    ViduTemplate::create([
                        'template_id' => $template->id,
                        'resource_id' => $viduTemplate['id'],
                        'resource' => json_encode($viduTemplate),
                        'name' => $viduTemplate['name'],
                        'resolution' => $viduTemplate['detail']['resolution'],
                        'duration' => $viduTemplate['detail']['duration'],
                        'aspect_ratio' => $viduTemplate['detail']['aspect_ratio'],
                        'credit_cost' => $viduTemplate['detail']['credit_cost'],
                        'scene' => $viduTemplate['detail']['scene'],
                        'category_id' => $category->id,
                        'cover_url' => $cover_path ?? '',
                        'type' => $viduTemplate['type'],
                        'video_url' => $video_path ?? '',
                        'image_count' => $input['image_count'] ?? null,
                        'image_url' => json_encode($local_images),
                        'person_count' => $input['person_count'] ?? null,
                        'attention' => $input['attention'] ?? null,
                        'prompt_instruction' => $input['prompt_instruction'] ?? null,
                        'effect_boundary' => $input['effect_boundary'] ?? null,
                        'request_body' => $new_request
                    ]);
                    DB::commit();
                }

                $page_token = $data['next_page_token'] ?? null;
            } catch (\Exception $e) {
                DB::rollBack();
                $this->error('Error: ' . $e->getMessage());
                break;
            }
        } while ($page_token);

        $this->info('Import completed.');
    }

    private function downloadFile($url, $type): ?string
    {
        if (!$url) {
            return null;
        }

        $client = new Client();
        try {
            $response = $client->get($url);
            $filename = basename(parse_url($url, PHP_URL_PATH));
            $path = 'vidu/' . $type . '/' . $filename;
            Storage::disk('public')->put($path, $response->getBody());
            return '/storage/' . $path;
        } catch (\Exception $e) {
            $this->error('Failed to download ' . $url . ': ' . $e->getMessage());
            return $url; // Fallback to original URL
        }
    }
}

