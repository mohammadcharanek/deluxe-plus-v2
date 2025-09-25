<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class ProductImage extends Model
{
    protected $fillable = [
        'product_id',
        'image',       // relative path under the "public" disk OR a full URL
        'alt',         // optional alt text
        'is_primary',  // bool
        'sort_order',  // int
    ];

    protected $casts = [
        'is_primary' => 'bool',
        'sort_order' => 'int',
    ];

    // Expose computed URLs if you ever JSON this model
    protected $appends = ['url', 'thumb_url'];

    /* ----------------------------- Relationships ----------------------------- */

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /* --------------------------------- Scopes -------------------------------- */

    public function scopeOrdered($q)
    {
        return $q->orderBy('is_primary', 'desc')
                 ->orderBy('sort_order')
                 ->orderBy('id');
    }

    public function scopePrimary($q)
    {
        return $q->where('is_primary', true);
    }

    /* ------------------------------- Accessors ------------------------------- */

    /**
     * Public URL to the image (supports full URLs or files on the "public" disk).
     */
    public function getUrlAttribute(): ?string
    {
        // Use Eloquent getter (respects accessors/mutators/casts)
        $path = $this->getAttribute('image');
        if (!$path) return null;

        // If absolute or scheme-relative, return as-is
        if (preg_match('~^(https?:)?//~i', $path)) {
            return $path;
        }

        // Normalize slashes and leading prefixes
        $normalized = str_replace('\\', '/', $path); // Windows safety
        $normalized = ltrim($normalized, '/');
        if (str_starts_with($normalized, 'storage/')) {
            $normalized = substr($normalized, strlen('storage/')); // -> 'foo/bar.jpg'
        }

        // Public URL via "public" disk (needs: php artisan storage:link)
        return Storage::disk('public')->url($normalized);
    }

    /**
     * Optional: a simple thumbnail URL (same file unless you generate thumbs).
     */
    public function getThumbUrlAttribute(): ?string
    {
        return $this->url;
    }

    /* ------------------------------- Mutators -------------------------------- */

    /**
     * Normalize stored path (strip leading "storage/" or "/").
     */
    public function setImageAttribute($value): void
    {
        if (is_string($value)) {
            $v = ltrim($value, '/');

            // If someone saved "storage/foo/bar.jpg", normalize to just "foo/bar.jpg"
            if (str_starts_with($v, 'storage/')) {
                $v = substr($v, strlen('storage/'));
            }

            $this->attributes['image'] = $v;
            return;
        }

        $this->attributes['image'] = $value;
    }

    /* ------------------------------- Lifecycle ------------------------------- */

    protected static function booted(): void
    {
        static::deleting(function (ProductImage $img) {
            // Delete file from "public" disk if it's not an external URL and exists
            $path = $img->getRawOriginal('image');

            if ($path && !preg_match('~^https?://~i', $path)) {
                $path = ltrim($path, '/');
                if (str_starts_with($path, 'storage/')) {
                    $path = substr($path, strlen('storage/'));
                }
                if (Storage::disk('public')->exists($path)) {
                    Storage::disk('public')->delete($path);
                }
            }
        });
    }

    /* --------------------------------- Helpers -------------------------------- */

    /**
     * Make this image the product’s primary image (and unset others).
     */
    public function makePrimary(): void
    {
        if (!$this->product_id) return;

        static::where('product_id', $this->product_id)->update(['is_primary' => false]);
        $this->is_primary = true;
        $this->save();
    }
}
