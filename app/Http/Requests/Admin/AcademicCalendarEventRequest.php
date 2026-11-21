<?php

namespace App\Http\Requests\Admin;

use App\Enums\CalendarEventType;
use App\Models\AcademicCalendarEvent;
use App\Models\Semester;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Libur nasional / UTS / UAS per semester (FR-RJN-04). Peran Admin dijaga
 * middleware `role:admin`; semester arsip read-only.
 */
class AcademicCalendarEventRequest extends FormRequest
{
    public function semester(): Semester
    {
        $semester = $this->route('semester');

        if ($semester instanceof Semester) {
            return $semester;
        }

        $event = $this->route('event');

        return $event instanceof AcademicCalendarEvent ? $event->semester : abort(404);
    }

    public function authorize(): bool
    {
        return $this->semester()->isEditable();
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'date_from' => ['required', 'date'],
            'date_to' => ['required', 'date', 'after_or_equal:date_from'],
            'type' => ['required', Rule::enum(CalendarEventType::class)],
            'label' => ['required', 'string', 'max:150'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $semester = $this->semester();

            if ($this->date('date_from')->lt($semester->start_date) || $this->date('date_to')->gt($semester->end_date)) {
                $validator->errors()->add('date_from', "Tanggal harus berada dalam semester {$semester->name} ({$semester->start_date->format('d/m/Y')} – {$semester->end_date->format('d/m/Y')}).");
            }
        }];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['date_from' => 'tanggal mulai', 'date_to' => 'tanggal selesai', 'type' => 'jenis', 'label' => 'keterangan'];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['date_to.after_or_equal' => 'Tanggal selesai tidak boleh sebelum tanggal mulai.'];
    }
}
