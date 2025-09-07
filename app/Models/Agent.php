<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Agent extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'uuid',
        'user_id',
        'agent_code',
        'department',
        'specialization',
        'status',
        'max_concurrent_tickets',
        'current_ticket_count',
        'performance_rating',
        'total_tickets_resolved',
        'average_response_time',
        'average_resolution_time',
        'working_hours',
        'skills',
        'notes',
        'last_activity_at'
    ];

    protected $casts = [
        'working_hours' => 'array',
        'skills' => 'array',
        'performance_rating' => 'decimal:2',
        'average_response_time' => 'decimal:2',
        'average_resolution_time' => 'decimal:2',
        'last_activity_at' => 'datetime'
    ];

    protected $hidden = [
        'id',
        'user_id'
    ];

    protected static function boot()
    {
        parent::boot();
        
        static::creating(function ($agent) {
            if (empty($agent->uuid)) {
                $agent->uuid = Str::uuid();
            }
            if (empty($agent->agent_code)) {
                $agent->agent_code = 'AGT' . strtoupper(Str::random(6));
            }
        });
    }

    /**
     * Relación con el usuario
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Relación con tickets asignados
     */
    public function tickets()
    {
        return $this->hasMany(Ticket::class, 'assigned_to', 'user_id');
    }

    /**
     * Scope para agentes activos
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Scope para agentes disponibles (no ocupados)
     */
    public function scopeAvailable($query)
    {
        return $query->where('status', 'active')
                    ->whereColumn('current_ticket_count', '<', 'max_concurrent_tickets');
    }

    /**
     * Scope por departamento
     */
    public function scopeByDepartment($query, $department)
    {
        return $query->where('department', $department);
    }

    /**
     * Scope por especialización
     */
    public function scopeBySpecialization($query, $specialization)
    {
        return $query->where('specialization', $specialization);
    }

    /**
     * Verificar si el agente está disponible para nuevos tickets
     */
    public function isAvailable()
    {
        return $this->status === 'active' && 
               $this->current_ticket_count < $this->max_concurrent_tickets;
    }

    /**
     * Incrementar contador de tickets
     */
    public function incrementTicketCount()
    {
        $this->increment('current_ticket_count');
        $this->touch('last_activity_at');
    }

    /**
     * Decrementar contador de tickets
     */
    public function decrementTicketCount()
    {
        if ($this->current_ticket_count > 0) {
            $this->decrement('current_ticket_count');
        }
        $this->touch('last_activity_at');
    }

    /**
     * Actualizar estadísticas de rendimiento
     */
    public function updatePerformanceStats($responseTime = null, $resolutionTime = null)
    {
        $this->increment('total_tickets_resolved');
        
        if ($responseTime !== null) {
            $this->average_response_time = $this->calculateNewAverage(
                $this->average_response_time,
                $responseTime,
                $this->total_tickets_resolved
            );
        }
        
        if ($resolutionTime !== null) {
            $this->average_resolution_time = $this->calculateNewAverage(
                $this->average_resolution_time,
                $resolutionTime,
                $this->total_tickets_resolved
            );
        }
        
        $this->save();
    }

    /**
     * Calcular nuevo promedio
     */
    private function calculateNewAverage($currentAverage, $newValue, $totalCount)
    {
        if ($currentAverage === null || $totalCount <= 1) {
            return $newValue;
        }
        
        return (($currentAverage * ($totalCount - 1)) + $newValue) / $totalCount;
    }

    /**
     * Obtener agente con menor carga de trabajo
     */
    public static function getLeastBusyAgent($department = null, $specialization = null)
    {
        $query = static::available();
        
        if ($department) {
            $query->byDepartment($department);
        }
        
        if ($specialization) {
            $query->bySpecialization($specialization);
        }
        
        return $query->orderBy('current_ticket_count', 'asc')
                    ->orderBy('performance_rating', 'desc')
                    ->first();
    }
}

