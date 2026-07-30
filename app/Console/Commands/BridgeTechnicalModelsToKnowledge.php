<?php

namespace App\Console\Commands;

use App\Domain\Knowledge\TechnicalModelKnowledgeManager;
use App\Models\TechnicalModel;
use DomainException;
use Illuminate\Console\Command;

class BridgeTechnicalModelsToKnowledge extends Command
{
    protected $signature = 'srcm:bridge-technical-models';

    protected $description =
        'Vincula modelos técnicos del catálogo con sus fichas de conocimiento.';

    public function handle(
        TechnicalModelKnowledgeManager $manager
    ): int {
        $technicalModels = TechnicalModel::query()
            ->orderBy('id')
            ->get();

        if ($technicalModels->isEmpty()) {
            $this->info(
                'No hay modelos técnicos para vincular.'
            );

            return self::SUCCESS;
        }

        foreach ($technicalModels as $technicalModel) {
            try {
                $linked = $manager->bridgeExisting(
                    $technicalModel
                );
            } catch (DomainException $exception) {
                $this->error(
                    sprintf(
                        '#%d %s: %s',
                        $technicalModel->id,
                        $technicalModel->code,
                        $exception->getMessage()
                    )
                );

                return self::FAILURE;
            }

            $this->line(
                sprintf(
                    '#%d %s → %s',
                    $linked->id,
                    $linked->code,
                    $linked->knowledgeEntity?->uuid
                        ?? 'sin ficha'
                )
            );
        }

        $this->info(
            'Puente catálogo ↔ conocimiento sincronizado.'
        );

        return self::SUCCESS;
    }
}
