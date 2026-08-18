<?php
namespace App\Models;
use App\Models\Concerns\BelongsToOrganization;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class FiscalDocumentNumber extends Model {
 use BelongsToOrganization;
 protected $fillable=['organization_id','fiscal_document_id','fiscal_point_of_sale_id','environment','number','assigned_at','assigned_by_user_id'];
 protected static function booted():void { static::updating(fn()=>throw new DomainException('La numeración fiscal es inmutable.')); static::deleting(fn()=>throw new DomainException('La numeración fiscal no puede eliminarse.')); }
 protected function casts():array{return ['number'=>'integer','assigned_at'=>'immutable_datetime'];}
 public function document():BelongsTo{return $this->belongsTo(FiscalDocument::class,'fiscal_document_id');}
 public function pointOfSale():BelongsTo{return $this->belongsTo(FiscalPointOfSale::class,'fiscal_point_of_sale_id');}
}
