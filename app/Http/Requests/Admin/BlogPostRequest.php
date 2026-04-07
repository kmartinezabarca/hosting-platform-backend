<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class BlogPostRequest extends FormRequest
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
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', 'unique:blog_posts,slug,' . ($this->route('blog_post') ? $this->route('blog_post')->uuid : null) . ',uuid'],
            'excerpt' => ['required', 'string', 'max:500'],
            'content' => ['required', 'string'],
            'image' => ['nullable', 'image', 'mimes:jpeg,png,jpg,gif,svg', 'max:2048'],
            'published_at' => ['nullable', 'date'],
            'user_id' => ['nullable', 'exists:users,id'],
            'author_name' => ['nullable', 'string', 'max:255'],
            'blog_category_id' => ['required', 'uuid', 'exists:blog_categories,uuid'],
            'is_featured' => ['boolean'],
            'read_time' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
