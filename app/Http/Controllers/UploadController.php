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

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $actualMimeType = finfo_file($finfo, $file->getRealPath());
        finfo_close($finfo);

        $allowedMimeTypes = [
            'image' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
            'cover' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
            'video' => ['video/mp4', 'video/quicktime', 'video/x-msvideo', 'video/x-ms-wmv', 'video/x-flv'],
        ];

        if (!in_array($actualMimeType, $allowedMimeTypes[$purpose] ?? [])) {
            return response()->json([
                'errors' => [
                    'file' => [
                        'Invalid file type. Expected: ' . implode(', ', $allowedMimeTypes[$purpose] ?? []) .
                        '. Got: ' . $actualMimeType
                    ]
                ]
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $maxSizes = [
            'image' => 5 * 1024 * 1024,  // 5MB in bytes
            'cover' => 5 * 1024 * 1024,  // 5MB in bytes
            'video' => 100 * 1024 * 1024, // 100MB in bytes
        ];

        if ($file->getSize() > ($maxSizes[$purpose] ?? PHP_INT_MAX)) {
            return response()->json([
                'errors' => [
                    'file' => ['File size exceeds maximum allowed: ' . ($maxSizes[$purpose] / 1024 / 1024) . 'MB']
                ]
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $folder = 'templates'; // Specific folder as per requirement
            $extension = $file->getClientOriginalExtension();

            $allowedExtensions = [
                'image' => ['jpg', 'jpeg', 'png', 'gif', 'webp'],
                'cover' => ['jpg', 'jpeg', 'png', 'gif', 'webp'],
                'video' => ['mp4', 'mov', 'avi', 'wmv', 'flv'],
            ];

            if (!in_array(strtolower($extension), $allowedExtensions[$purpose] ?? [])) {
                // Force correct extension based on MIME type
                $extensionMap = [
                    'image/jpeg' => 'jpg',
                    'image/png' => 'png',
                    'image/gif' => 'gif',
                    'image/webp' => 'webp',
                    'video/mp4' => 'mp4',
                    'video/quicktime' => 'mov',
                    'video/x-msvideo' => 'avi',
                    'video/x-ms-wmv' => 'wmv',
                    'video/x-flv' => 'flv',
                ];

                $extension = $extensionMap[$actualMimeType] ?? 'bin';
            }

            $filename = Str::uuid() . '.' . strtolower($extension);
            $path = $file->storeAs($folder, $filename, 'public');

            return $this->success(
                data: ['path' => $path],
                message: 'File uploaded successfully',
                status: Response::HTTP_CREATED
            );
        } catch (\Exception $e) {
            return $this->error(errors: null, message: $e->getMessage());
        }
    }

}