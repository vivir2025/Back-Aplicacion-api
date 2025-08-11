<?php

namespace App\Http\Controllers;

use App\Models\Sede;
use Illuminate\Http\Request;

class SedeController extends Controller
{
    public function index()
    {
        return Sede::all();
    }

    public function store(Request $request)
    {
        $request->validate([
            'nombresede' => 'required|unique:sedes',
        ]);

        $sede = Sede::create($request->all());

        return response()->json($sede, 201);
    }

    public function show($id)
    {
        return Sede::findOrFail($id);
    }

    public function update(Request $request, $id)
    {
        $sede = Sede::findOrFail($id);

        $request->validate([
            'nombresede' => 'required|unique:sedes,nombresede,'.$sede->id,
        ]);

        $sede->update($request->all());

        return response()->json($sede);
    }

    public function destroy($id)
    {
        Sede::findOrFail($id)->delete();
        return response()->json(null, 204);
    }
}