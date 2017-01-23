<?php

namespace App\Http\Controllers;

use App\Group;
use App\File;
use Illuminate\Http\Response;
use Illuminate\Http\Request;
use Image;
use Auth;
use Storage;
use Gate;

class FileController extends Controller
{

    public function __construct()
    {
        $this->middleware('member', ['only' => ['create', 'store', 'edit', 'update', 'destroy']]);
        $this->middleware('verified', ['only' => ['create', 'store', 'edit', 'update', 'destroy']]);
        $this->middleware('cache', ['only' => ['index', 'show']]);
        $this->middleware('public', ['only' => ['index', 'gallery', 'thumbnail', 'preview']]);
    }

    /**
    * Display a listing of the resource.
    *
    * @return Response
    */
    public function index(Group $group)
    {
        // list all files and folders without parent id's (parent_id=NULL)
        //$files = $group->files()->with('user')->orderBy('updated_at', 'desc')->get();
        $files = $group->files()
        ->with('user')
        ->whereNull('parent_id')
        ->orderBy('item_type', 'desc')
        ->orderBy('name', 'asc')
        ->orderBy('updated_at', 'desc')
        ->get();

        return view('files.index')
        ->with('parent_id', null)
        ->with('files', $files)
        ->with('group', $group)
        ->with('tab', 'files');
    }


    public function gallery(Group $group)
    {
        $files = $group->files()
        ->with('user')
        ->where('mime', 'like', 'image/jpeg')
        ->orWhere('mime', 'like', 'image/png')
        ->orWhere('mime', 'like', 'image/gif')
        ->orderBy('updated_at', 'desc')
        ->paginate(100);


        return view('files.gallery')
        ->with('files', $files)
        ->with('group', $group)
        ->with('tab', 'files');
    }




    /**
    * Display the specified resource.
    *
    * @param int $id
    *
    * @return Response
    */
    public function show(Group $group, File $file)
    {

        if ($file->parent_id)
        {
            $parent_id = $file->parent_id;
        }
        else
        {
            $parent_id = null;
        }

        // view depends on file type
        // folder :

        if ($file->isFolder())
        {
            return view('files.index')
            ->with('files', $file->getChildren())
            ->with('parent_id', $parent_id)
            ->with('file', $file)
            ->with('group', $group)
            ->with('tab', 'files');
        }


        // file
        if ($file->isFile())
        {
            return view('files.show')
            ->with('file', $file)
            ->with('group', $group)
            ->with('tab', 'files');
        }

        // link
        if ($file->isLink())
        {
            return view('files.link')
            ->with('file', $file)
            ->with('group', $group)
            ->with('tab', 'files');
        }




    }


    /**
    * Display the specified resource.
    *
    * @param int $id
    *
    * @return Response
    */
    public function download(Group $group, File $file)
    {
        if (Storage::exists($file->path)) {
            //return response()->download($file->path, $file->original_filename);
            return (new Response(Storage::get($file->path), 200))
            ->header('Content-Type', $file->mime)
            ->header('Content-Disposition', 'inline; filename="' . $file->original_filename . '"');
        } else {
            abort(404, 'File not found in storage at ' . $file->path);
        }
    }

    public function thumbnail(Group $group, File $file)
    {
        if (in_array($file->mime, ['image/jpeg', 'image/png', 'image/gif']))
        {
            $cachedImage = Image::cache(function($img) use ($file) {
                return $img->make(storage_path().'/app/'.$file->path)->fit(32, 32);
            }, 60000, true);

            return $cachedImage->response();
        }

        if ($file->isFolder())
        {
            return redirect('images/extensions/folder.png');
        }



        return redirect('images/extensions/text-file.png');

    }


    public function preview(Group $group, File $file)
    {

        if (in_array($file->mime, ['image/jpeg', 'image/png', 'image/gif']))
        {
            $cachedImage = Image::cache(function($img) use ($file) {
                return $img->make(storage_path().'/app/'.$file->path)->fit(250,250);
            }, 60000, true);

            return $cachedImage->response();
        }
        else
        {
            return redirect('images/extensions/text-file.png');
        }
    }


    /************************** Files handling methods **********************/

    /**
    * Show the form for creating a new resource.
    *
    * @return Response
    */
    public function create(Request $request, Group $group)
    {

        if ($request->get('parent_id'))
        {
            $parent = File::findOrFail($request->get('parent_id'));
        }
        else
        {
            $parent = null;
        }

        return view('files.create')
        ->with('parent', $parent)
        ->with('group', $group)
        ->with('tab', 'files');
    }



    /**
    * Store a new file.
    *
    * @return Response
    */
    public function store(Request $request, Group $group)
    {

        try {
            $file = new File();

            // we save it first to get an ID from the database, it will later be used to generate a unique filename.
            $file->forceSave(); // we bypass autovalidation, since we don't have a complete model yet, but we *need* an id

            // add group
            $file->group()->associate($group);
            $file->user()->associate(Auth::user());


            // generate filenames and path
            $filepath = '/groups/'.$file->group->id.'/files/';


            $filename = $file->id . '.' . strtolower($request->file('file')->getClientOriginalExtension());

            // resize big images only if they are png, gif or jpeg
            if (in_array ($request->file('file')->getClientMimeType(), ['image/jpeg', 'image/png', 'image/gif']))
            {
                Storage::disk('local')->makeDirectory($filepath);
                Image::make($request->file('file'))->widen(1200, function ($constraint) {
                    $constraint->upsize();
                })
                ->save(storage_path().'/app/' . $filepath.$filename);
            }
            else
            {
                // store the file
                Storage::disk('local')->put($filepath.$filename,  file_get_contents($request->file('file')->getRealPath()) );
            }

            // add path and other infos to the file record on DB
            $file->path = $filepath.$filename;
            $file->name = $request->file('file')->getClientOriginalName();
            $file->original_filename = $request->file('file')->getClientOriginalName();
            $file->mime = $request->file('file')->getClientMimeType();


            // handle parenting
            if ($request->has('parent_id'))
            {
                $parent = File::findOrFail($request->get('parent_id'));
                $parent->addChild($file);
            }

            // save it again
            $file->save();


            if ($request->ajax())
            {
                return response()->json('success', 200);
            }
            else
            {

                flash()->info(trans('messages.ressource_created_successfully'));
                if (isset($parent))
                {
                    return redirect()->action('FileController@show', [$group, $parent]);
                }
                else
                {
                    return redirect()->action('FileController@index', $group);
                }
            }
        }
        catch (Exception $e)
        {

            if ($request->ajax())
            {
                return response()->json($e->getMessage(), 400);
            }
            else
            {
                abort(400, $e->getMessage());
            }
        }
    }


    /**
    * Show the form for editing the specified resource.
    *
    * @param int $id
    *
    * @return Response
    */
    public function edit(Group $group, File $file)
    {
        // get all folders
        $folders = $group->files()->where('item_type', File::FOLDER)->get();

        return view('files.edit')
        ->with('folders', $folders)
        ->with('file', $file)
        ->with('group', $group)
        ->with('tab', 'file');
    }

    /**
    * Update the specified resource in storage.
    *
    * @param int $id
    *
    * @return Response
    */
    public function update(Request $request, Group $group, File $file)
    {
        $parent = File::findOrFail($request->get('parent_id'));
        $parent->addChild($file);

        flash()->info(trans('messages.ressource_updated_successfully'));
        return redirect()->action('FileController@show', [$group, $parent]);
    }




    public function destroyConfirm(Request $request, Group $group, File $file)
    {
        if (Gate::allows('delete', $file))
        {
            return view('files.delete')
            ->with('group', $group)
            ->with('file', $file)
            ->with('tab', 'file');
        }
        else
        {
            abort(403);
        }
    }



    /**
    * Remove the specified resource from storage.
    *
    * @param int $id
    *
    * @return \Illuminate\Http\Response
    */
    public function destroy(Request $request, Group $group, File $file)
    {

        $parent = $file->getParent();

        if (Gate::allows('delete', $file))
        {
            $file->delete();
            flash()->info(trans('messages.ressource_deleted_successfully'));

            if ($parent)
            {
                return redirect()->action('FileController@show', [$group, $parent]);
            }
            else
            {
                return redirect()->action('FileController@index', [$group]);
            }
        }
        else
        {
            abort(403);
        }
    }


    /************************** Folder handling methods *****************/

    /**
    * Show the form for creating a folder.
    *
    * @return Response
    */
    public function createFolder(Request $request, Group $group)
    {
        if ($request->get('parent_id'))
        {
            $parent = File::findOrFail($request->get('parent_id'));
        }
        else
        {
            $parent = null;
        }

        return view('files.create_folder')
        ->with('parent', $parent)
        ->with('group', $group)
        ->with('tab', 'files');
    }

    /**
    * Store the folder in the file DB.
    *
    * @return Response
    */
    public function storeFolder(Request $request, Group $group)
    {

        $file = new File;
        $file->name = $request->get('folder');
        $file->path = $request->get('folder');

        $file->item_type = File::FOLDER;

        // add group
        $file->group()->associate($group);

        // handle parenting
        if ($request->has('parent_id'))
        {
            $parent = File::findOrFail($request->get('parent_id'));
            $parent->addChild($file);
        }



        // add user
        $file->user()->associate(Auth::user());

        if ($file->save())
        {
            flash()->info(trans('messages.ressource_created_successfully'));
            if (isset($parent))
            {
                return redirect()->action('FileController@show', [$group, $file]);
            }
            else
            {
                return redirect()->action('FileController@index', $group);
            }

        }
        else
        {
            dd('folder creation failed');
        }

    }




}
