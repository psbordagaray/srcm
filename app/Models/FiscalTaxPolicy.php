<?php
namespace App\Models;
use App\Models\Concerns\BelongsToOrganization;use DomainException;use Illuminate\Database\Eloquent\Model;
class FiscalTaxPolicy extends Model {use BelongsToOrganization;protected $fillable=['organization_id','version','effective_from','default_tax_treatment','created_by_user_id'];protected static function booted():void{static::updating(fn()=>throw new DomainException('Una política tributaria fiscal es inmutable.'));static::deleting(fn()=>throw new DomainException('Una política tributaria fiscal no puede eliminarse.'));}protected function casts():array{return ['version'=>'integer','effective_from'=>'immutable_date'];}}
