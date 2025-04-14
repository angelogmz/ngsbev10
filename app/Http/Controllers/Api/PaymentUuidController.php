<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class PaymentUuidController extends Controller
{
    public function addUuids()
    {

        // Check if column exists, add if not
        if (!Schema::hasColumn('payments', 'pymnt_id')) {
            Schema::table('payments', function ($table) {
                $table->uuid('pymnt_id')->unique()->nullable()->after('id');
            });
        }

        // Process in batches to avoid memory issues
        $updatedCount = 0;
        DB::table('payments')
        ->where(function($query) {
            $query->whereNull('pymnt_id')
                  ->orWhere('pymnt_id', '');
        })
        ->orderBy('id')->chunk(200, function ($payments) use (&$updatedCount) {
            foreach ($payments as $payment) {
                DB::table('payments')
                    ->where('id', $payment->id)
                    ->update(['pymnt_id' => Str::uuid()]);
                $updatedCount++;
            }
        });

        return response()->json([
            'message' => 'UUIDs added successfully',
            'updated_count' => $updatedCount
        ]);
    }
}
