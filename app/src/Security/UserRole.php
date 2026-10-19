<?php

namespace App\Security;

enum UserRole: string
{
    case User = 'ROLE_USER';
    case Student = 'ROLE_STUDENT';
    case Teacher = 'ROLE_TEACHER';
    case Curator = 'ROLE_CURATOR';
    case HeadTeacher = 'ROLE_HEAD_TEACHER';
    case Director = 'ROLE_DIRECTOR';
    case Admin = 'ROLE_ADMIN';

    public function label(): string
    {
        return match ($this) {
            self::User => 'Користувач',
            self::Student => 'Студент',
            self::Teacher => 'Викладач',
            self::Curator => 'Куратор',
            self::HeadTeacher => 'Завуч',
            self::Director => 'Директор',
            self::Admin => 'Адміністратор',
        };
    }
}
