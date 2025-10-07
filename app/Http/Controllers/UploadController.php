<?php

namespace App\Http\Controllers;

use App\Traits\ResponseTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class UploadController extends Controller
{
    use ResponseTrait;

    /**
     * Upload a file with specified purpose.
     */
    public function upload(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'file' => 'required|file',
            'purpose' => 'required|in:image,cover,video',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $purpose = $request->purpose;
        $file = $request->file('file');

        // Dynamic mime validation based on purpose
        $rules = [
            'image' => 'mimes:jpg,jpeg,png,gif|max:5120', // 5MB
            'cover' => 'mimes:jpg,jpeg,png,gif|max:5120', // 5MB
            'video' => 'mimes:mp4,mov,avi,wmv,flv|max:102400', // 100MB
        ];

        $additionalValidator = Validator::make($request->all(), [
            'file' => $rules[$purpose] ?? '',
        ]);

        if ($additionalValidator->fails()) {
            return response()->json(['errors' => $additionalValidator->errors()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $folder = 'templates'; // Specific folder as per requirement
            $extension = $file->getClientOriginalExtension();
            $filename = Str::uuid() . '.' . $extension;
            $path = $file->storeAs($folder, $filename, 'public');

            return $this->success(
                data: ['path' => $path],
                message: 'File uploaded successfully',
                status: Response::HTTP_CREATED
            );
        } catch (\Exception $e) {
            return $this->error($e->getMessage(), $e->getCode());
        }
    }

}