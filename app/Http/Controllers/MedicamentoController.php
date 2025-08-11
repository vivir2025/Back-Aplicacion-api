<?php

namespace App\Http\Controllers;

use App\Models\Medicamento;
use Illuminate\Http\Request;

class MedicamentoController extends Controller
{
    public function index(Request $request)
    {
        $query = Medicamento::query();
        
        if ($request->has('busqueda')) {
            $query->where('nombmedicamento', 'like', '%'.$request->busqueda.'%');
        }
        
        return $query->get();
    }

    public function store(Request $request)
    {
        $request->validate([
            'nombmedicamento' => 'required|unique:medicamentos',
        ]);

        $medicamento = Medicamento::create($request->all());

        return response()->json($medicamento, 201);
    }

    public function show($id)
    {
        return Medicamento::findOrFail($id);
    }

    public function update(Request $request, $id)
    {
        $medicamento = Medicamento::findOrFail($id);

        $request->validate([
            'nombmedicamento' => 'required|unique:medicamentos,nombmedicamento,'.$medicamento->id,
        ]);

        $medicamento->update($request->all());

        return response()->json($medicamento);
    }

    public function destroy($id)
    {
        Medicamento::findOrFail($id)->delete();
        return response()->json(null, 204);
    }
}