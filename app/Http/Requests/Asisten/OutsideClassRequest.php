<?php

namespace App\Http\Requests\Asisten;

use App\Models\OutsideClass;
use App\Models\Semester;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Kelas di Luar Lab IR (US-RJN-06, PRD 3.6). Hanya Sekretaris/Bendahara dan
 * Admin; semester arsip read-only.
 */
class OutsideClassRequest extends FormRequest
{
    public function semesterId(): int
    {
        $class = $this->route('outside_class');

        return $class instanceof OutsideClass ? $class->semester_id : (int) $this->input('semester_id');
    }

    public function authorize(): bool
    {
        if (! $this->user()?->canManageNgawasRecap()) {
            return false;
        }

        $semester = Semester::find($this->semesterId());

        return $semester === null || $semester->isEditable();
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $class = $this->route('outside_class');

        return [
            // Semester tidak dapat dipindah setelah dibuat.
            'semester_id' => $class instanceof OutsideClass ? ['prohibited'] : ['required', 'integer', 'exists:semesters,id'],
            'course_name' => ['required', 'string', 'max:150'],
            'class_name' => [
                'required', 'string', 'max:50',
                Rule::unique('outside_classes', 'class_name')
                    ->where('semester_id', $this->semesterId())->where('course_name', $this->input('course_name'))
                    ->whereNull('deleted_at')->ignore($class instanceof OutsideClass ? $class->id : null),
            ],
            'credits' => ['required', 'integer', 'between:1,6'],
            'origin_lab' => ['required', 'string', 'max:100'],
            'day_of_week' => ['nullable', 'integer', 'between:1,7'],
            'start_time' => ['nullable', 'date_format:H:i', 'required_with:end_time'],
            'end_time' => ['nullable', 'date_format:H:i', 'required_with:start_time', 'after:start_time'],
            'assistant_ids' => ['required', 'array', 'min:1'],
            'assistant_ids.*' => ['integer', 'distinct', Rule::exists('users', 'id')->where('role', 'asisten')->where('is_active', true)],
            'lab_confirmed' => ['boolean'],
            'lab_confirmation_note' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'course_name' => 'nama mata kuliah', 'class_name' => 'nama kelas', 'credits' => 'SKS', 'origin_lab' => 'lab asal',
            'day_of_week' => 'hari', 'start_time' => 'jam mulai', 'end_time' => 'jam selesai', 'assistant_ids' => 'asisten',
            'lab_confirmation_note' => 'catatan konfirmasi',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'class_name.unique' => 'Kelas ini sudah terdaftar pada semester tersebut.',
            'end_time.after' => 'Jam selesai harus setelah jam mulai.',
            'assistant_ids.required' => 'Pilih minimal satu asisten yang ditugaskan.',
            'assistant_ids.min' => 'Pilih minimal satu asisten yang ditugaskan.',
        ];
    }
}
