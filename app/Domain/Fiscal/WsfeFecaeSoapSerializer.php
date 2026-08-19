<?php

namespace App\Domain\Fiscal;

interface WsfeFecaeSoapSerializer
{
    /**
     * @return array<string,string>
     */
    public function headers(
        WsfeFecaeSoap11Call $call
    ): array;

    public function body(
        WsfeFecaeSoap11Call $call
    ): string;
}
