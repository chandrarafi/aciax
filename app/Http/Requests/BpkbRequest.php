<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class BpkbRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'nomesin' => ['required', 'string', 'max:16', 'exists:pgsql_nms.stokunit,no_mesin'],
            'nobpkb' => ['required', 'string', 'max:20'],
            'images' => ['required', 'array', 'size:4'],
            'images.*' => ['required', 'image', 'mimes:jpeg,png,jpg', 'max:1048'],
        ];
    }

    public function messages(): array
    {
        return [
            'nomesin.required' => 'Nomor Mesin harus diisi.',
            'nomesin.string' => 'Nomor Mesin harus berupa string.',
            'nomesin.max' => 'Nomor Mesin tidak boleh lebih dari 16 karakter.',
            'nomesin.exists' => 'Nomor Mesin tidak ditemukan di database.',
            'nobpkb.required' => 'Nomor BPKB harus diisi.',
            'nobpkb.string' => 'Nomor BPKB harus berupa string.',
            'nobpkb.max' => 'Nomor BPKB tidak boleh lebih dari 20 karakter.',
            'images.required' => 'Gambar harus diunggah.',
            'images.array' => 'Gambar harus berupa array.',
            'images.size' => 'Harus mengunggah tepat 4 gambar.',
            'images.*.required' => 'Setiap gambar harus diunggah.',
            'images.*.image' => 'File yang diunggah harus berupa gambar.',
            'images.*.mimes' => 'Gambar harus berformat JPEG, PNG, atau JPG.',
            'images.*.max' => 'Ukuran gambar tidak boleh lebih dari 1 MB.',
        ];
    }
}
