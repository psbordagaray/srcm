<?php

namespace App\Domain\Fiscal;

interface WsfeCompUltimoAutorizadoSoapSerializer
{
    /** @return array<string,string> */
    public function headers(WsfeCompUltimoAutorizadoSoap11Call $call): array;

    public function body(WsfeCompUltimoAutorizadoSoap11Call $call): string;
}
