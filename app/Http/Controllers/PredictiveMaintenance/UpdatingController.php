<?php

namespace App\Http\Controllers\PredictiveMaintenance;

use App\Http\Controllers\Controller;
use App\Models\PredictiveMaintenanceExecution;
use App\Models\PredictiveMaintenanceSchedule;
use App\Models\User;
use App\Helpers\DataFilterHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class UpdatingController extends Controller
{
    /**
     * Display a listing of executions that need updating.
     */
    public function index()
    {
        // Get all executions (not just pending/in_progress) to check if jadwal is fully completed
        // IMPORTANT: 1 jadwal (schedule_id + scheduled_date) = 1 execution (latest status)
        $query = PredictiveMaintenanceExecution::with(['schedule.machineErp.machineType', 'schedule.machineErp.roomErp', 'schedule.standard', 'performedBy', 'schedule']);
        
        // Filter by user role (mekanik only sees their own assigned executions)
        if (DataFilterHelper::shouldFilterRoute(request()->route()->getName())) {
            $user = auth()->user();
            if ($user && $user->role === 'mekanik' && $user->id) {
                $query->where('performed_by', $user->id);
            }
        }
        
        $allExecutions = $query->orderBy('scheduled_date', 'asc')->orderBy('created_at', 'desc')->get();
        
        // Group all executions by (machine_erp_id, scheduled_date) 
        // For each jadwal, use only the LATEST execution (1 jadwal = 1 execution)
        $allJadwalData = [];
        foreach ($allExecutions as $execution) {
            $machineId = $execution->schedule->machine_erp_id;
            $scheduledDate = $execution->scheduled_date;
            if (is_string($scheduledDate)) {
                $dateFormatted = $scheduledDate;
            } else {
                $dateFormatted = \Carbon\Carbon::parse($scheduledDate)->format('Y-m-d');
            }
            $key = $machineId . '_' . $dateFormatted;
            
            // For each jadwal, only keep the latest execution (most recent created_at)
            if (!isset($allJadwalData[$key])) {
                $allJadwalData[$key] = [
                    'machine_id' => $machineId,
                    'machine' => $execution->schedule->machineErp,
                    'scheduled_date' => $dateFormatted,
                    'latest_execution' => $execution, // Store only the latest execution
                    'has_pending' => false,
                    'has_in_progress' => false,
                    'has_completed' => false,
                ];
            } else {
                // Update if this execution is newer (later created_at)
                if ($execution->created_at > $allJadwalData[$key]['latest_execution']->created_at) {
                    $allJadwalData[$key]['latest_execution'] = $execution;
                }
            }
            
            // Update status flags based on latest execution
            $latestExec = $allJadwalData[$key]['latest_execution'];
            if ($latestExec->status == 'pending') {
                $allJadwalData[$key]['has_pending'] = true;
                $allJadwalData[$key]['has_in_progress'] = false;
                $allJadwalData[$key]['has_completed'] = false;
            } elseif ($latestExec->status == 'in_progress') {
                $allJadwalData[$key]['has_in_progress'] = true;
                $allJadwalData[$key]['has_pending'] = false;
                $allJadwalData[$key]['has_completed'] = false;
            } elseif ($latestExec->status == 'completed') {
                $allJadwalData[$key]['has_completed'] = true;
                $allJadwalData[$key]['has_pending'] = false;
                $allJadwalData[$key]['has_in_progress'] = false;
            }
        }
        
        // Filter: only include jadwal that have pending or in_progress status (latest execution)
        // Exclude jadwal that already have completed status (completed is the latest update)
        $jadwalData = [];
        foreach ($allJadwalData as $key => $jadwal) {
            $latestExecution = $jadwal['latest_execution'];
            
            // Skip jadwal that already has completed status (latest execution is completed)
            if ($latestExecution->status == 'completed') {
                continue;
            }
            
            // Only include jadwal with pending or in_progress status (latest execution)
            if ($latestExecution->status == 'pending' || $latestExecution->status == 'in_progress') {
                $jadwalData[$key] = [
                    'machine_id' => $jadwal['machine_id'],
                    'machine' => $jadwal['machine'],
                    'scheduled_date' => $jadwal['scheduled_date'],
                    'executions' => [$latestExecution], // Only the latest execution
                    'status' => $latestExecution->status,
                ];
            }
        }
        
        // Convert to collection and paginate manually
        $jadwalCollection = collect($jadwalData)->values();
        $currentPage = \Illuminate\Pagination\LengthAwarePaginator::resolveCurrentPage();
        $perPage = 12;
        $currentItems = $jadwalCollection->slice(($currentPage - 1) * $perPage, $perPage)->all();
        $jadwalPaginator = new \Illuminate\Pagination\LengthAwarePaginator(
            $currentItems,
            $jadwalCollection->count(),
            $perPage,
            $currentPage,
            ['path' => \Illuminate\Pagination\LengthAwarePaginator::resolveCurrentPath()]
        );
        
        // Get completed jadwal for information only - use latest execution for each jadwal
        $completedJadwal = [];
        foreach ($allJadwalData as $key => $jadwal) {
            $latestExecution = $jadwal['latest_execution'];
            
            // Only include jadwal where latest execution is completed
            if ($latestExecution->status == 'completed') {
                $completedJadwal[$key] = [
                    'machine_id' => $jadwal['machine_id'],
                    'machine' => $jadwal['machine'],
                    'scheduled_date' => $jadwal['scheduled_date'],
                    'executions' => [$latestExecution], // Only the latest execution
                    'latest_end_time' => $latestExecution->actual_end_time,
                ];
            }
        }
        
        // Sort by latest_end_time and limit to 20
        usort($completedJadwal, function($a, $b) {
            $timeA = $a['latest_end_time'] ? \Carbon\Carbon::parse($a['latest_end_time']) : \Carbon\Carbon::createFromTimestamp(0);
            $timeB = $b['latest_end_time'] ? \Carbon\Carbon::parse($b['latest_end_time']) : \Carbon\Carbon::createFromTimestamp(0);
            return $timeB->gt($timeA) ? 1 : -1;
        });
        $completedJadwal = array_slice($completedJadwal, 0, 20);
        
        // Convert to collection and paginate manually
        $completedJadwalCollection = collect($completedJadwal);
        $currentPageCompleted = \Illuminate\Pagination\LengthAwarePaginator::resolveCurrentPage('completed_page');
        $perPageCompleted = 12;
        $currentItemsCompleted = $completedJadwalCollection->slice(($currentPageCompleted - 1) * $perPageCompleted, $perPageCompleted)->all();
        $completedJadwalPaginator = new \Illuminate\Pagination\LengthAwarePaginator(
            $currentItemsCompleted,
            $completedJadwalCollection->count(),
            $perPageCompleted,
            $currentPageCompleted,
            [
                'path' => \Illuminate\Pagination\LengthAwarePaginator::resolveCurrentPath(),
                'pageName' => 'completed_page',
            ]
        );
        
        // Statistics - only count jadwal based on latest execution status
        $pendingCount = 0;
        $inProgressCount = 0;
        
        // Count based on latest execution status for each jadwal
        foreach ($allJadwalData as $key => $jadwal) {
            $latestExecution = $jadwal['latest_execution'];
            
            // Only count if latest execution is not completed
            if ($latestExecution->status == 'pending') {
                $pendingCount++;
            } elseif ($latestExecution->status == 'in_progress') {
                $inProgressCount++;
            }
        }
        
        // Count unique completed jadwal
        $completedJadwalCount = count($completedJadwal);
        $totalJadwalToUpdate = count($jadwalData);
        
        $stats = [
            'pending' => $pendingCount,
            'in_progress' => $inProgressCount,
            'completed' => $completedJadwalCount,
            'total' => $totalJadwalToUpdate,
        ];
        
        return view('predictive-maintenance.updating.index', compact('jadwalPaginator', 'completedJadwalPaginator', 'stats'));
    }
    
    /**
     * Get maintenance points by machine and date for AJAX.
     */
    public function getMaintenancePointsByMachineAndDate(Request $request)
    {
        $machineId = $request->input('machine_id');
        $scheduledDate = $request->input('scheduled_date');
        
        if (!$machineId || !$scheduledDate) {
            return response()->json(['maintenance_points' => []]);
        }
        
        $executions = PredictiveMaintenanceExecution::whereHas('schedule', function ($query) use ($machineId, $scheduledDate) {
            $query->where('machine_erp_id', $machineId)
                  ->whereDate('start_date', $scheduledDate);
        })
        ->with(['schedule.maintenancePoint', 'schedule.standard', 'performedBy'])
        ->orderBy('id', 'asc')
        ->get();
        
        $maintenancePoints = [];
        foreach ($executions as $execution) {
            $maintenancePoints[] = [
                'execution_id' => $execution->id,
                'schedule_id' => $execution->schedule->id,
                'maintenance_point_name' => $execution->schedule->maintenancePoint->name ?? $execution->schedule->title,
                'standard_name' => $execution->schedule->standard->name ?? '-',
                'standard_unit' => $execution->schedule->standard->unit ?? '-',
                'standard_min' => $execution->schedule->standard->min_value,
                'standard_max' => $execution->schedule->standard->max_value,
                'standard_target' => $execution->schedule->standard->target_value,
                'measured_value' => $execution->measured_value,
                'measurement_status' => $execution->measurement_status,
                'instruction' => $execution->schedule->maintenancePoint->instruction ?? $execution->schedule->description ?? '',
                'photo' => $execution->schedule->maintenancePoint && $execution->schedule->maintenancePoint->photo ? asset('public-storage/' . $execution->schedule->maintenancePoint->photo) : null,
                'status' => $execution->status,
                'performed_by' => $execution->performedBy->name ?? '-',
                'actual_start_time' => $execution->actual_start_time ? \Carbon\Carbon::parse($execution->actual_start_time)->format('d/m/Y H:i') : '-',
                'actual_end_time' => $execution->actual_end_time ? \Carbon\Carbon::parse($execution->actual_end_time)->format('d/m/Y H:i') : '-',
            ];
        }
        
        return response()->json(['maintenance_points' => $maintenancePoints]);
    }
    
    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        $execution = PredictiveMaintenanceExecution::with(['schedule.machineErp.machineType', 'schedule.machineErp.roomErp', 'schedule.standard', 'schedule.maintenancePoint', 'performedBy'])
            ->findOrFail($id);
        $users = User::whereIn('role', ['mekanik', 'team_leader', 'group_leader', 'coordinator'])->get();
        
        return view('predictive-maintenance.updating.edit', compact('execution', 'users'));
    }
    
    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $validated = $request->validate([
            'status' => 'required|in:pending,in_progress,completed,skipped,cancelled',
            'measured_value' => 'nullable|numeric',
            'actual_start_time' => 'nullable|date',
            'actual_end_time' => 'nullable|date|after:actual_start_time',
            'performed_by' => 'nullable|exists:users,id',
            'findings' => 'nullable|string',
            'actions_taken' => 'nullable|string',
            'notes' => 'nullable|string',
            'cost' => 'nullable|numeric|min:0',
            'checklist' => 'nullable|array',
            'checklist.*.item' => 'required|string',
            'checklist.*.checked' => 'boolean',
            'checklist.*.notes' => 'nullable|string',
            'photo_before' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'photo_after' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
        ]);
        
        $execution = PredictiveMaintenanceExecution::findOrFail($id);
        
        // Calculate measurement_status based on standard
        $measuredValue = $validated['measured_value'] ?? null;
        $measurementStatus = null;
        
        if ($measuredValue !== null && $execution->schedule && $execution->schedule->standard) {
            $measurementStatus = $execution->schedule->standard->getMeasurementStatus($measuredValue);
        }
        
        $validated['measurement_status'] = $measurementStatus;
        
        // Handle photo uploads
        if ($request->hasFile('photo_before')) {
            if ($execution->photo_before && Storage::disk('public')->exists($execution->photo_before)) {
                Storage::disk('public')->delete($execution->photo_before);
            }
            $validated['photo_before'] = $request->file('photo_before')->store('pm-executions', 'public');
        }
        
        if ($request->hasFile('photo_after')) {
            if ($execution->photo_after && Storage::disk('public')->exists($execution->photo_after)) {
                Storage::disk('public')->delete($execution->photo_after);
            }
            $validated['photo_after'] = $request->file('photo_after')->store('pm-executions', 'public');
        }
        
        // Auto set start time if status changed to in_progress
        if ($validated['status'] == 'in_progress' && !$execution->actual_start_time) {
            $validated['actual_start_time'] = now();
        }
        
        // Auto set end time if status changed to completed
        if ($validated['status'] == 'completed' && !$execution->actual_end_time) {
            $validated['actual_end_time'] = now();
        }
        
        // Recalculate measurement_status if measured_value is provided and standard exists
        if (isset($validated['measured_value']) && $execution->schedule && $execution->schedule->standard) {
            $validated['measurement_status'] = $execution->schedule->standard->getMeasurementStatus($validated['measured_value']);
        }
        
        $execution->update($validated);
        
        return redirect()->route('predictive-maintenance.updating.index')
            ->with('success', 'Execution berhasil diupdate.');
    }
}
