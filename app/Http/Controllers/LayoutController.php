<?php

namespace App\Http\Controllers;

use App\Models\Layout;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class LayoutController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
    }
    public function layouts()
    {
        $data = Layout::select('page')->distinct()->get();
        return response()->json(['data' => $data]);
    }

    public function position($layout)
    {
        $data = Layout::where('page', $layout)->select('position')->distinct()->get();
        return response()->json(['data' => $data]);
    }

    public function positionLayout($layout, $position)
    {
        $data = Layout::where('page', $layout)->where('position', $position)->get();
        return response()->json(['data' => $data]);
    }
    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }



    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'page' => ['required', 'string'],
            'position' => ['required', 'string'],
            'serial' => ['required', 'numeric'],
            'status'=>['required'],
            'link' => ['string'],
            'url' => ['mimes:jpeg,png,jpg,gif,webp,avif,mp4', 'max:2048'],
            'visibility' => ['required', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 422);
        }

        $validatedData = $validator->validated();

        if ($request->hasFile('url')) {
            try {
                $thumbnail = $request->file('url');
                $thumbnailPath = $thumbnail->store('layouts', 'public');
                $validatedData['url'] = $thumbnailPath;
            } catch (\Exception $e) {
                return response()->json(['error' => 'File upload failed'], 500);
            }
        }

        $post = Layout::create($validatedData);

        return response()->json(['status' => 'success', 'data' => $post, 'message' => 'Layout stored successfully']);
    }


    /**
     * Display the specified resource.
     */
    public function show(Layout $layout)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Layout $layout)
    {
        //
    }


    public function uploadFile(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'thumbnail' => ['required', 'mimes:jpeg,png,jpg,gif,webp,avif,mp4', 'max:2048'],
            'old_url' => ['nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 422);
        }

        if ($request->hasFile('thumbnail')) {
            try {
                // Delete the old file if the old_url is provided
                if ($request->filled('old_url')) {
                    Storage::disk('public')->delete($request->input('old_url'));
                }

                $thumbnail = $request->file('thumbnail');
                $thumbnailPath = $thumbnail->store('layouts', 'public');

                return response()->json(['status' => 'success', 'url' => $thumbnailPath], 201);
            } catch (\Exception $e) {
                return response()->json(['error' => 'File upload failed'], 500);
            }
        }

        return response()->json(['error' => 'No file uploaded'], 400);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'page' => ['required', 'string'],
            'position' => ['required', 'string'],
            'serial' => ['required', 'numeric'],
            'status'=>['required'],
            'link' => ['string'],
            'url' => ['string'],
            'visibility' => ['string'],
        ]);

        if ($validator->fails()) {
            return response()->json(['error' => $validator->errors()], 422);
        }

        $validatedData = $validator->validated();

        $post = Layout::find($id);
        if (!$post) {
            return response()->json(['error' => 'Layout not found'], 404);
        }

        // if ($request->hasFile('url')) {
        //     try {
        //         // Delete old file if exists
        //         if ($post->url) {
        //             Storage::disk('public')->delete($post->url);
        //         }

        //         $thumbnail = $request->file('url');
        //         $thumbnailPath = $thumbnail->store('layouts', 'public');
        //         $validatedData['url'] = $thumbnailPath;
        //     } catch (\Exception $e) {
        //         return response()->json(['error' => 'File upload failed'], 500);
        //     }
        // }

        $post->update($validatedData);

        return response()->json(['status' => 'success', 'data' => $post, 'message' => 'Layout updated successfully']);
    }


    /**
     * Remove the specified resource from storage.
     */
    public function destroy($id)
    {
        $post = Layout::find($id);
        if (!$post) {
            return response()->json(['error' => 'Layout not found'], 404);
        }

        // Delete the file if exists
        if ($post->url) {
            Storage::disk('public')->delete($post->url);
        }

        $post->delete();

        return response()->json(['status' => 'success', 'message' => 'Layout deleted successfully']);
    }
}
