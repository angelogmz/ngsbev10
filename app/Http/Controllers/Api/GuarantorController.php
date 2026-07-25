<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Guarantor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class GuarantorController extends Controller
{
    /**
     * Add a new guarantor.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function addGuarantor(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'title' => 'nullable|string|max:5',
            'name' => 'required|string|max:200',
            'contract_no' => 'required|string|max:50',
            'nic' => 'required|string|max:20|unique:guarantors,nic',
            'date_of_birth' => 'required|date_format:Y-m-d', // Changed from 'date' to 'date_format:Y-m-d'
            'civil_status' => 'nullable|string|max:50',
            'contact_no' => 'required|string|max:20',
            'address' => 'required|string|max:200',
            'email' => 'nullable|email|max:50|unique:guarantors,email'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 422,
                'errors' => $validator->messages()
            ], 422);
        }

        try {
            $guarantor = Guarantor::create([
                'title' => $request->title,
                'name' => $request->name,
                'contract_no' => $request->contract_no,
                'nic' => $request->nic,
                'date_of_birth' => $request->date_of_birth,
                'civil_status' => $request->civil_status,
                'contact_no' => $request->contact_no,
                'address' => $request->address,
                'email' => $request->email
            ]);

            return response()->json([
                'status' => 200,
                'message' => 'Guarantor added successfully!',
                'guarantor' => $guarantor
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 500,
                'message' => 'Failed to add guarantor: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get guarantors by contract number.
     *
     * @param  string  $contract_no
     * @return \Illuminate\Http\JsonResponse
     */
    public function searchByContract($contract_no)
    {
        $guarantors = Guarantor::where('contract_no', $contract_no)->get();

        if ($guarantors->count() > 0) {
            return response()->json([
                'status' => 200,
                'guarantors' => $guarantors
            ], 200);
        }

        return response()->json([
            'status' => 404,
            'message' => 'No guarantors found for this contract'
        ], 404);
    }

    /**
     * Get a single guarantor by ID for editing.
     *
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function getGuarantor($id)
    {
        $guarantor = Guarantor::find($id);

        if ($guarantor) {
            return response()->json([
                'status' => 200,
                'guarantor' => $guarantor
            ], 200);
        }

        return response()->json([
            'status' => 404,
            'message' => 'Guarantor not found'
        ], 404);
    }

    /**
     * Update a guarantor.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateGuarantor(Request $request, $id)
    {
        $guarantor = Guarantor::find($id);

        if (!$guarantor) {
            return response()->json([
                'status' => 404,
                'message' => 'Guarantor not found'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'title' => 'nullable|string|max:5',
            'name' => 'required|string|max:200',
            'contract_no' => 'required|string|max:50',
            'nic' => 'string|max:20,' . $id,
            'date_of_birth' => 'nullable|date_format:d/m/Y', // Format: DD/MM/YYYY
            'civil_status' => 'nullable|string|max:50',
            'contact_no' => 'required|string|max:20',
            'address' => 'required|string|max:200',
            'email' => 'nullable|email|max:50|unique:guarantors,email,' . $id
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 422,
                'errors' => $validator->messages()
            ], 422);
        }

        try {
            $guarantor->update([
                'title' => $request->title,
                'name' => $request->name,
                'contract_no' => $request->contract_no,
                'nic' => $request->nic,
                'date_of_birth' => $request->date_of_birth,
                'civil_status' => $request->civil_status,
                'contact_no' => $request->contact_no,
                'address' => $request->address,
                'email' => $request->email
            ]);

            return response()->json([
                'status' => 200,
                'message' => 'Guarantor updated successfully!',
                'guarantor' => $guarantor
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'status' => 500,
                'message' => 'Failed to update guarantor: ' . $e->getMessage()
            ], 500);
        }
    }
}
