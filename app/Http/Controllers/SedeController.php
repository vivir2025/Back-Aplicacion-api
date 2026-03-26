<?php

namespace App\Http\Controllers;

use App\Models\Sede;
use Illuminate\Http\Request;

/**
 * @group Sedes
 *
 * Gestión de las sedes de atención en salud disponibles en el sistema.
 */
class SedeController extends Controller
{
    /**
     * Listar sedes
     * 
     * @authenticated
     */
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