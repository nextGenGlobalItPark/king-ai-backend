<?php

namespace App\Console\Commands;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

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
                $response = $client->get($url);
                $status = $response->getStatusCode();
                $body = $response->getBody()->getContents();

                if ($status !== 200) {
                    $this->error('Failed to fetch data from API.');
                    break;
                }

                $data = json_decode($body, true);

                foreach ($data['template_list'] ?? [] as $template) {
                    if (ViduTemplate::where('resource_id', $template['id'])->exists()) {
                        continue;
                    }

                    $category = Category::firstOrCreate(['name' => $template['category_name'], 'source' => 'vidu']);

                    $cover_path = $this->downloadFile($template['cover_url'] ?? null, 'images');
                    $video_path = $this->downloadFile($template['video_url'] ?? null, 'videos');

                    $viduTemplate = ViduTemplate::create([
                        'resource_id' => $template['id'],
                        'resource' => json_encode($template),
                        'name' => $template['name'],
                        'resolution' => $template['detail']['resolution'],
                        'duration' => $template['detail']['duration'],
                        'aspect_ratio' => $template['detail']['aspect_ratio'],
                        'credit_cost' => $template['detail']['credit_cost'],
                        'scene' => $template['detail']['scene'],
                        'category_id' => $category->id,
                        'cover_url' => $cover_path ?? '',
                        'type' => $template['type'],
                        'video_url' => $video_path ?? '',
                    ]);

                    $input = $template['input_instruction'] ?? [];
                    $local_images = [];
                    foreach ($input['image_url'] ?? [] as $img_url) {
                        $local_images[] = $this->downloadFile($img_url, 'images');
                    }

                    ViduInputInstruction::create([
                        'vidu_template_id' => $viduTemplate->id,
                        'image_count' => $input['image_count'] ?? null,
                        'image_url' => json_encode($local_images),
                        'person_count' => $input['person_count'] ?? null,
                        'attention' => $input['attention'] ?? null,
                        'prompt_instruction' => $input['prompt_instruction'] ?? null,
                        'effect_boundary' => $input['effect_boundary'] ?? null,
                    ]);

                    $api_ex = $template['api_example'] ?? [];
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

                    ViduApiExample::create([
                        'vidu_template_id' => $viduTemplate->id,
                        'request' => $new_request,
                    ]);
                }

                $page_token = $data['next_page_token'] ?? null;
            } catch (\Exception $e) {
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
