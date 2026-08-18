<?php
namespace Tests\Feature\Fiscal;
use App\Domain\Fiscal\ArcaHomologationReadiness;use DomainException;use Tests\TestCase;
class ArcaHomologationReadinessTest extends TestCase {public function test_homologation_is_disabled_and_production_is_hard_blocked_by_default():void{$this->assertFalse(app(ArcaHomologationReadiness::class)->enabled());$this->expectException(DomainException::class);app(ArcaHomologationReadiness::class)->assertReady();}}
