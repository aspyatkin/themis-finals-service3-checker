<?php

class Result
{
    const UP = 101;
    const CORRUPT = 102;
    const MUMBLE = 103;
    const DOWN = 104;
    const INTERNAL_ERROR = 110;

    public static function getName($value)
    {
        $namesMap = [
            101 => 'UP',
            102 => 'CORRUPT',
            103 => 'MUMBLE',
            104 => 'DOWN',
            110 => 'INTERNAL_ERROR'
        ];
        return $namesMap[$value];
    }
}
