<?php

namespace tests\app\libraries\database;

use app\libraries\Core;
use app\libraries\database\AbstractDatabase;
use app\libraries\database\DatabaseQueries;
use app\libraries\database\SqliteDatabase;
use app\models\Config;
use app\models\User;
use PHPUnit\Framework\TestCase;

class UserContactInfoQueriesTester extends TestCase {
    public static function studentEnrollmentProvider(): array {
        return [
            'no enrollment' => [[], 'student', false],
            'current student' => [[['student', 'f26', 'current', 4, '1']], 'student', true],
            'archived student' => [[['student', 's20', 'past', 4, '1']], 'student', true],
            'dropped historical student' => [[['student', 's20', 'past', 4, null]], 'student', true],
            'instructor only' => [[['student', 'f26', 'current', 1, '1']], 'student', false],
            'graders only' => [[['student', 'f26', 'current', 2, '1'], ['student', 's20', 'past', 3, '1']], 'student', false],
            'mixed roles' => [[['student', 'f26', 'current', 1, '1'], ['student', 's20', 'past', 4, null]], 'student', true],
            'multiple student enrollments' => [[['student', 'f26', 'current', 4, '1'], ['student', 's20', 'past', 4, '1']], 'student', true],
            'another user is a student' => [[['other', 'f26', 'current', 4, '1']], 'student', false],
            'user ID is bound' => [[['student', 'f26', 'current', 4, '1']], "student' OR 1=1 --", false]
        ];
    }

    /**
     * @dataProvider studentEnrollmentProvider
     */
    public function testStudentEligibilityAcrossRecordedEnrollments(array $memberships, string $user_id, bool $expected): void {
        // Isolated in-memory fixtures; no installed master/course database is connected.
        $database = new SqliteDatabase(['memory' => true]);
        $database->connect(false);
        $database->query('CREATE TABLE courses (term TEXT, course TEXT, status INTEGER)');
        $database->query("INSERT INTO courses VALUES ('f26', 'current', 1), ('s20', 'past', 2)");
        $database->query('CREATE TABLE courses_users (user_id TEXT, term TEXT, course TEXT, user_group INTEGER, registration_section TEXT)');
        foreach ($memberships as $membership) {
            $database->query('INSERT INTO courses_users VALUES (?, ?, ?, ?, ?)', $membership);
        }
        $core = $this->createMock(Core::class);
        $core->method('getConfig')->willReturn($this->createMock(Config::class));
        $core->method('getSubmittyDB')->willReturn($database);
        $core->expects($this->never())->method('getCourseDB');
        $this->assertSame($expected, (new DatabaseQueries($core))->hasStudentEnrollment($user_id));
        $this->assertSame($expected ? 1 : 0, $database->getRowCount());
    }

    public function testOnlyContactFieldsAreWrittenToMaster(): void {
        $core = $this->createMock(Core::class);
        $core->method('getConfig')->willReturn($this->createMock(Config::class));
        $database = $this->createMock(AbstractDatabase::class);
        $core->method('getSubmittyDB')->willReturn($database);
        $core->expects($this->never())->method('getCourseDB');
        $user = new User($core);
        $user->setId('student');
        $user->setLinkedinUsername('student-name');
        $user->setGithubUsername('student-code');
        $user->setPersonalWebpage('https://example.com/?a=1&b=2');
        $calls = 0;
        $database->expects($this->exactly(2))->method('query')->willReturnCallback(function ($sql, $params) use (&$calls) {
            $this->assertStringContainsString('WHERE user_id=?', $sql);
            $this->assertStringNotContainsString('user_email', $sql);
            $this->assertStringNotContainsString('user_givenname', $sql);
            $expected = $calls++ === 0
                ? ['student-name', 'student-code', 'https://example.com/?a=1&b=2', 'student']
                : ['', '', '', 'student'];
            $this->assertSame($expected, $params);
        });
        $queries = new DatabaseQueries($core);
        $queries->updateUserContactInfo($user);
        $user->setLinkedinUsername('');
        $user->setGithubUsername('');
        $user->setPersonalWebpage('');
        $queries->updateUserContactInfo($user);
    }
}
