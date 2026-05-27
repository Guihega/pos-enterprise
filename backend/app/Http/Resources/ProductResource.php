<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Catalog\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Product
 */
class ProductResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'sku' => $this->sku,
            'name' => $this->name,
            'description' => $this->description,
            'short_description' => $this->short_description,

            'pricing' => [
                'cost' => (float) $this->cost,
                'price' => (float) $this->price,
                'compare_at_price' => $this->compare_at_price !== null ? (float) $this->compare_at_price : null,
                'min_price' => $this->min_price !== null ? (float) $this->min_price : null,
                'has_discount' => $this->hasDiscount(),
                'margin_percent' => $this->margin_percent,
            ],

            'flags' => [
                'track_inventory' => $this->track_inventory,
                'is_sellable' => $this->is_sellable,
                'is_purchasable' => $this->is_purchasable,
                'allow_decimals' => $this->allow_decimals,
            ],

            'status' => $this->status,
            'published_at' => $this->published_at?->toIso8601String(),

            'category' => $this->whenLoaded('category', fn () => $this->category ? [
                'uuid' => $this->category->uuid,
                'name' => $this->category->name,
                'slug' => $this->category->slug,
            ] : null),

            'brand' => $this->whenLoaded('brand', fn () => $this->brand ? [
                'uuid' => $this->brand->uuid,
                'name' => $this->brand->name,
                'slug' => $this->brand->slug,
            ] : null),

            'unit' => $this->whenLoaded('unit', fn () => [
                'uuid' => $this->unit->uuid,
                'code' => $this->unit->code,
                'name' => $this->unit->name,
                'symbol' => $this->unit->symbol,
            ]),

            'tax' => $this->whenLoaded('tax', fn () => $this->tax ? [
                'uuid' => $this->tax->uuid,
                'code' => $this->tax->code,
                'name' => $this->tax->name,
                'rate' => (float) $this->tax->rate,
                'is_inclusive' => $this->tax->is_inclusive,
            ] : null),

            'barcodes' => $this->whenLoaded('barcodes', fn () => $this->barcodes->map(fn ($b) => [
                'barcode' => $b->barcode,
                'type' => $b->type,
                'is_primary' => $b->is_primary,
                'pack_quantity' => (float) $b->pack_quantity,
            ])),

            'images' => $this->whenLoaded('images', fn () => $this->images->map(fn ($i) => [
                'uuid' => $i->uuid,
                'url' => $i->url,
                'thumbnail_url' => $i->thumbnail_url,
                'alt_text' => $i->alt_text,
                'is_primary' => $i->is_primary,
                'sort_order' => $i->sort_order,
            ])),

            'weight' => $this->weight !== null ? (float) $this->weight : null,
            'weight_unit' => $this->weight_unit,
            'dimensions' => $this->dimensions,
            'tax_code' => $this->tax_code,

            'custom_attributes' => $this->custom_attributes ?? [],
            'metadata' => $this->metadata ?? [],

            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
