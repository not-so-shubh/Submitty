<?php

namespace tests\app\controllers\admin;

use app\controllers\admin\UsersController;
use app\models\User;
use tests\BaseUnitTest;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

class UsersControllerTester extends BaseUnitTest {
    use \phpmock\phpunit\PHPMock;

    protected function tearDown(): void {
        unset($_COOKIE['active_student_columns']);
    }

    private function makeUser($core): User {
        return new User($core, [
            'user_id' => 'teacher', 'user_givenname' => 'Teacher', 'user_familyname' => 'Example',
            'user_email' => 'teacher@example.com', 'user_group' => User::GROUP_INSTRUCTOR,
            'linkedin_username' => 'teacher-name', 'github_username' => 'teacher-code',
            'personal_webpage' => 'https://example.com/?a=1&b=2'
        ]);
    }

    public function testRosterDataAndColumnPreferences(): void {
        $core = $this->createMockCore(['semester' => 'f26', 'course' => 'sample']);
        $core->getQueries()->method('getAllUsers')->willReturn([$this->makeUser($core)]);
        $this->getFunctionMock('app\controllers\admin', 'setcookie')->expects($this->exactly(3))->willReturn(true);
        $controller = new UsersController($core);
        $old = '0-1-0-1-0-1-0-1-0-1-0-1-0-1-0-1';
        $current = $old . '-1-0-1';
        $preferences = [
            '' => '1-0-1-1-1-1-1-1-1-1-1-0-0-0-0-0-0-0-0',
            $old => $old . '-0-0-0',
            $current => $current,
            'malformed' => '1-0-1-1-1-1-1-1-1-1-1-0-0-0-0-0-0-0-0'
        ];
        foreach ($preferences as $cookie => $expected) {
            $_COOKIE['active_student_columns'] = $cookie;
            $response = $controller->getStudents();
            $this->assertSame($expected, $response->web_response->parameters[7]);
            $data = $response->json_response->json['data'][0];
            $this->assertSame('teacher-name', $data['linkedin_username']);
            $this->assertSame('teacher-code', $data['github_username']);
            $this->assertSame('https://example.com/?a=1&b=2', $data['personal_webpage']);
            $this->assertSame('teacher@example.com', $data['email']);
        }
    }

    public function testContactTableRenderingAndUnsafeStoredValues(): void {
        $core = $this->createMockCore();
        $user = $this->makeUser($core);
        $twig = new Environment(new FilesystemLoader(__DIR__ . '/../../../../app/templates'), ['autoescape' => 'html']);
        $twig->addFunction(new TwigFunction('uniqid', fn() => uniqid()));
        $context = ['sections' => ['NULL' => [$user]], 'active_student_columns' => array_fill(0, 19, 1)];
        $html = $twig->render('admin/users/StudentList.twig', $context);
        $this->assertStringContainsString('href="https://www.linkedin.com/in/teacher-name"', $html);
        $this->assertStringContainsString('href="https://github.com/teacher-code"', $html);
        $this->assertStringContainsString('href="https://example.com/?a=1&amp;b=2"', $html);
        $this->assertStringContainsString('rel="noopener noreferrer"', $html);
        $this->assertStringContainsString('colspan="22"', $html);
        foreach (['linkedin_username', 'github_username', 'personal_webpage'] as $field) {
            $this->assertStringContainsString('"sortKey":"' . $field . '"', $html);
        }

        $user->setPersonalWebpage('javascript:alert(1)');
        $user->setGithubUsername('"><script>alert(1)</script>');
        $html = $twig->render('admin/users/StudentList.twig', $context);
        $this->assertStringNotContainsString('href="javascript:alert(1)"', $html);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    }

    public static function classlistContactColumnsProvider(): array {
        return [
            'exported existing course user' => [true, true, true],
            'exported new course membership' => [false, true, true],
            'exported new Submitty user' => [false, false, true],
            'legacy classlist' => [true, true, false]
        ];
    }

    /**
     * @dataProvider classlistContactColumnsProvider
     */
    public function testClasslistAcceptsExportedColumnsWithoutEditingProfiles(bool $enrolled, bool $master_exists, bool $contacts): void {
        $core = $this->createMockCore(['semester' => 'f26', 'course' => 'sample']);
        $user = $this->makeUser($core);
        $user->setId('student');
        $user->setGroup(User::GROUP_STUDENT);
        $queries = $core->getQueries();
        $queries->method('getAllUsers')->willReturn($enrolled ? [$user] : []);
        $queries->method('getUserById')->with('student')->willReturn($user);
        $queries->method('getSubmittyUser')->with('student')->willReturn($master_exists ? $user : null);
        $queries->method('getRegistrationSections')->willReturn([]);
        $queries->expects($this->never())->method('updateUserContactInfo');
        $queries->expects($enrolled || $master_exists ? $this->never() : $this->once())->method('insertSubmittyUser');
        $queries->expects($enrolled ? $this->once() : $this->never())->method('updateUser')
            ->with($this->identicalTo($user), 'f26', 'sample');
        $queries->expects($enrolled ? $this->never() : $this->once())->method('insertCourseUser')
            ->with($this->callback(function (User $inserted): bool {
                $this->assertSame('student', $inserted->getId());
                // New course users receive master contact values through the synchronization trigger.
                $this->assertSame('', $inserted->getLinkedinUsername());
                $this->assertSame('', $inserted->getGithubUsername());
                $this->assertSame('', $inserted->getPersonalWebpage());
                return true;
            }), 'f26', 'sample');
        $core->expects($this->never())->method('addErrorMessage');
        $core->expects($this->once())->method('addSuccessMessage')
            ->with('Uploaded roster.csv: (' . ($enrolled ? '0 added, 1 updated' : '1 added, 0 updated') . ')');
        $core->expects($this->once())->method('redirect');

        $headers = 'Given Name,Family Name,User ID,Email,Secondary Email,UTC Offset,Time Zone,Registration Section,Registration Subsection,Rotation Section,Group';
        $row = 'Student,Example,student,student@example.com,,-5,America/New_York,NULL,,,Student';
        if ($contacts) {
            $headers .= ',LinkedIn Username,GitHub Username,Personal Webpage';
            // These exported fields are ignored even when their values would be invalid profile input.
            $row .= ',changed--name,changed--code,javascript:alert(1)';
        }
        $this->getFunctionMock('app\controllers\admin', 'mime_content_type')->expects($this->once())->willReturn('text/csv');
        $this->getFunctionMock('app\controllers\admin', 'file_get_contents')->expects($this->once())->willReturn($headers . "\n" . $row . "\n");
        // Exercise the CSV parser without creating or removing uploaded files.
        $this->getFunctionMock('app\controllers\admin', 'register_shutdown_function')->expects($this->once());
        $saved_files = $_FILES;
        $saved_post = $_POST;
        try {
            $_FILES['upload'] = ['name' => 'roster.csv', 'tmp_name' => '/unused/roster.csv'];
            $_POST = [];
            (new UsersController($core))->uploadUserList();
            $this->assertSame('teacher-name', $user->getLinkedinUsername());
            $this->assertSame('teacher-code', $user->getGithubUsername());
            $this->assertSame('https://example.com/?a=1&b=2', $user->getPersonalWebpage());
        }
        finally {
            $_FILES = $saved_files;
            $_POST = $saved_post;
        }
    }

    public static function hyphenRoundTripProvider(): array {
        $fixtures = json_decode(file_get_contents(__DIR__ . '/../../../data/roster_hyphen_roundtrip.json'), true, 512, JSON_THROW_ON_ERROR);
        $cases = [];
        foreach ($fixtures as $fixture) {
            foreach ([true, false] as $enrolled) {
                $label = $fixture['code'] . ' ' . $fixture['user']['user_id'] . ($enrolled ? ' existing' : ' new membership');
                $cases[$label] = [$fixture, $enrolled];
                $data = $fixture['user'];
                $legacy = $fixture;
                $legacy['csv'] = str_replace(
                    [",'{$data['user_id']},", "\"'{$data['reg_section']}\"", "\"'{$data['reg_subsection']}\""],
                    [",{$data['user_id']},", "\"{$data['reg_section']}\"", "\"{$data['reg_subsection']}\""],
                    $fixture['csv']
                );
                // Legacy imports trim whitespace; protected subsection prefixes retain leading spaces.
                $legacy['user']['reg_subsection'] = trim($data['reg_subsection']);
                $cases[$label . ' legacy unescaped'] = [$legacy, $enrolled];
            }
        }
        return $cases;
    }

    /**
     * @dataProvider hyphenRoundTripProvider
     */
    public function testProtectedRosterFieldsImport(array $fixture, bool $enrolled): void {
        $data = $fixture['user'];
        $grader = $fixture['code'] === 'grader';
        $core = $this->createMockCore(['semester' => 'f26', 'course' => 'sample']);
        $user = $this->makeUser($core);
        $user->setId($data['user_id']);
        $user->setLegalGivenName($data['given_name']);
        $user->setLegalFamilyName($data['family_name']);
        $user->setEmail($data['email']);
        $user->setSecondaryEmail($data['secondary_email'] ?? '');
        $this->assertTrue(User::validateUserData('user_id', $data['user_id']));
        $this->assertTrue(User::validateUserData($grader ? 'grading_assignments' : 'registration_section', $data['reg_section']));
        $this->assertTrue(User::validateUserData('registration_subsection', $data['reg_subsection']));
        $this->assertTrue(User::validateUserData('user_legal_givenname', $data['given_name']));
        $this->assertTrue(User::validateUserData('user_email', $data['email']));

        $queries = $core->getQueries();
        $queries->method('getAllUsers')->willReturn($enrolled ? [$user] : []);
        $queries->method('getUserById')->with($data['user_id'])->willReturn($user);
        $queries->method('getSubmittyUser')->with($data['user_id'])->willReturn($user);
        $queries->method('getRegistrationSections')->willReturn([
            ['sections_registration_id' => '-section'], ['sections_registration_id' => 'other']
        ]);
        $queries->expects($this->never())->method('updateUserContactInfo');
        $queries->expects($this->never())->method('insertSubmittyUser');
        $queries->expects($grader ? $this->never() : $this->once())->method('insertNewRegistrationSection')
            ->with($data['reg_section']);
        $check_user = $this->callback(function (User $saved) use ($data, $grader, $enrolled): bool {
            $this->assertSame($data['user_id'], $saved->getId());
            $this->assertSame($data['reg_subsection'], $saved->getRegistrationSubsection());
            if (!$grader) {
                $this->assertSame($data['reg_section'], $saved->getRegistrationSection());
            }
            $this->assertSame($data['given_name'], $saved->getLegalGivenName());
            $this->assertSame($data['email'], $saved->getEmail());
            $this->assertSame($enrolled ? 'teacher-code' : '', $saved->getGithubUsername());
            return true;
        });
        $queries->expects($enrolled ? $this->once() : $this->never())->method('updateUser')
            ->with($check_user, 'f26', 'sample');
        $queries->expects($enrolled ? $this->never() : $this->once())->method('insertCourseUser')
            ->with($check_user, 'f26', 'sample');
        $core->expects($this->never())->method('addErrorMessage');
        $core->expects($this->once())->method('addSuccessMessage');
        $core->expects($this->once())->method('redirect');
        $this->uploadCSV(new UsersController($core), $fixture['csv'], $grader ? 'graderlist' : 'classlist');
        $this->assertSame('teacher-name', $user->getLinkedinUsername());
        $this->assertSame('teacher-code', $user->getGithubUsername());
        $this->assertSame('https://example.com/?a=1&b=2', $user->getPersonalWebpage());
    }

    public static function spreadsheetRoundTripProvider(): array {
        $fixtures = json_decode(file_get_contents(__DIR__ . '/../../../data/roster_spreadsheet_roundtrip.json'), true, 512, JSON_THROW_ON_ERROR);
        $cases = [];
        foreach ($fixtures as $fixture) {
            foreach ([[false, false], [false, true], [true, true]] as [$enrolled, $master_exists]) {
                $cases[$fixture['name'] . ($enrolled ? ' existing course user' : ($master_exists ? ' new membership' : ' new master user'))] = [$fixture, $enrolled, $master_exists];
            }
            // Handwritten, unmarked CSV must retain literal apostrophes too.
            $legacy = $fixture;
            $keys = ['given_name', 'family_name', 'user_id', 'email', 'secondary_email', 'reg_section', 'reg_subsection', 'group'];
            $legacy['csv'] = "Given Name,Family Name,User ID,Email,Secondary Email,Registration Section,Registration Subsection,Group\n";
            $legacy['csv'] .= implode(',', array_map(fn($key) => '"' . str_replace('"', '""', $fixture['user'][$key]) . '"', $keys)) . "\n";
            $cases[$fixture['name'] . ' unmarked input'] = [$legacy, false, false];
        }
        return $cases;
    }

    /**
     * @dataProvider spreadsheetRoundTripProvider
     */
    public function testSpreadsheetRosterRoundTrip(array $fixture, bool $enrolled, bool $master_exists): void {
        $data = $fixture['user'];
        $grader = $fixture['code'] === 'grader';
        $core = $this->createMockCore(['semester' => 'f26', 'course' => 'sample']);
        $user = $this->makeUser($core);
        $user->setId($data['user_id']);
        foreach (['given_name' => 'user_legal_givenname', 'family_name' => 'user_legal_familyname', 'email' => 'user_email', 'secondary_email' => 'user_email_secondary'] as $key => $field) {
            $this->assertTrue(User::validateUserData($field, $data[$key]), $key);
        }
        $queries = $core->getQueries();
        $queries->method('getAllUsers')->willReturn($enrolled ? [$user] : []);
        $queries->method('getSubmittyUser')->with($data['user_id'])->willReturn($master_exists ? $user : null);
        $queries->method('getUserById')->with($data['user_id'])->willReturn($user);
        $queries->method('getRegistrationSections')->willReturn([
            ['sections_registration_id' => '-section'], ['sections_registration_id' => 'other']
        ]);
        $queries->expects($this->never())->method('updateUserContactInfo');
        $check_user = $this->callback(function (User $saved) use ($data, $enrolled, $grader): bool {
            $this->assertSame($data['user_id'], $saved->getId());
            $this->assertSame($enrolled ? 'Teacher' : $data['given_name'], $saved->getLegalGivenName());
            $this->assertSame($enrolled ? 'Example' : $data['family_name'], $saved->getLegalFamilyName());
            $this->assertSame($enrolled ? 'teacher@example.com' : $data['email'], $saved->getEmail());
            $this->assertSame($enrolled ? '' : $data['secondary_email'], $saved->getSecondaryEmail());
            $this->assertSame($data['reg_subsection'], $saved->getRegistrationSubsection());
            $this->assertSame($grader ? User::GROUP_FULL_ACCESS_GRADER : User::GROUP_STUDENT, $saved->getGroup());
            if (!$grader) {
                $this->assertSame($data['reg_section'], $saved->getRegistrationSection());
            }
            $this->assertSame($enrolled ? 'teacher-name' : '', $saved->getLinkedinUsername());
            $this->assertSame($enrolled ? 'teacher-code' : '', $saved->getGithubUsername());
            $this->assertSame($enrolled ? 'https://example.com/?a=1&b=2' : '', $saved->getPersonalWebpage());
            return true;
        });
        $queries->expects($master_exists ? $this->never() : $this->once())->method('insertSubmittyUser')->with($check_user);
        $queries->expects($enrolled ? $this->never() : $this->once())->method('insertCourseUser')->with($check_user, 'f26', 'sample');
        $queries->expects($enrolled ? $this->once() : $this->never())->method('updateUser')->with($check_user, 'f26', 'sample');
        $core->expects($this->never())->method('addErrorMessage');
        $core->expects($this->once())->method('addSuccessMessage');
        $core->expects($this->once())->method('redirect');
        $this->uploadCSV(new UsersController($core), $fixture['csv'], $grader ? 'graderlist' : 'classlist');
        $this->assertSame('teacher-name', $user->getLinkedinUsername());
        $this->assertSame('teacher-code', $user->getGithubUsername());
        $this->assertSame('https://example.com/?a=1&b=2', $user->getPersonalWebpage());
    }

    public static function invalidProtectedRosterFieldsProvider(): array {
        return [
            'hyphen formula' => ['classlist', "'-1+2", '1', 'sub'],
            'equals formula' => ['classlist', "'=1+1", '1', 'sub'],
            'plus formula' => ['classlist', "'+SUM(1)", '1', 'sub'],
            'at formula' => ['classlist', "'@SUM(1)", '1', 'sub'],
            'ordinary apostrophe' => ['classlist', "'student", '1', 'sub'],
            'repeated prefix' => ['classlist', "''-student", '1', 'sub'],
            'space-prefixed user ID' => ['classlist', "' -student", '1', 'sub'],
            'overlong section' => ['classlist', 'student', "'-" . str_repeat('a', 20), 'sub'],
            'overlong subsection' => ['classlist', 'student', '1', "'-" . str_repeat('a', 20)],
            'invalid subsection' => ['classlist', 'student', '1', "'-sub$"],
            'control-prefixed subsection' => ['classlist', 'student', '1', "' \t-sub"],
            'malformed grading list' => ['graderlist', 'grader', "'-section,,other", 'sub'],
            'unknown grading section' => ['graderlist', 'grader', "'-missing,other", 'sub'],
            'marked repeated prefix' => ['classlist', "''-student", '1', 'sub', 'apostrophe-v1'],
            'marked formula' => ['classlist', "'=1+1", '1', 'sub', 'apostrophe-v1'],
            'unknown CSV encoding' => ['classlist', 'student', '1', 'sub', 'apostrophe-v2'],
            'empty CSV encoding' => ['classlist', 'student', '1', 'sub', '']
        ];
    }

    /**
     * @dataProvider invalidProtectedRosterFieldsProvider
     */
    public function testProtectedRosterFieldsStillRequireValidation(string $list_type, string $id, string $section, string $subsection, ?string $encoding = null): void {
        $core = $this->createMockCore(['semester' => 'f26', 'course' => 'sample']);
        $queries = $core->getQueries();
        $queries->method('getRegistrationSections')->willReturn([
            ['sections_registration_id' => '-section'], ['sections_registration_id' => 'other']
        ]);
        $queries->expects($this->never())->method('getAllUsers');
        $queries->expects($this->never())->method('insertSubmittyUser');
        $queries->expects($this->never())->method('insertCourseUser');
        $queries->expects($this->never())->method('updateUser');
        $queries->expects($this->never())->method('updateUserContactInfo');
        $core->expects($this->once())->method('addErrorMessage')->with($this->stringContains('Invalid'));
        $core->expects($this->once())->method('redirect')->willThrowException(new \RuntimeException('Invalid CSV rejected'));
        $headers = ['User ID', 'Registration Section', 'Registration Subsection'];
        $values = [$id, $section, $subsection];
        if ($encoding !== null) {
            $headers[] = 'CSV Encoding';
            $values[] = $encoding;
        }
        $csv = implode(',', $headers) . "\n";
        $csv .= implode(',', array_map(fn($value) => '"' . str_replace('"', '""', $value) . '"', $values)) . "\n";
        $this->expectExceptionMessage('Invalid CSV rejected');
        $this->uploadCSV(new UsersController($core), $csv, $list_type);
    }

    public function testInvalidEncodingOnLaterRowPreventsAllWrites(): void {
        $core = $this->createMockCore(['semester' => 'f26', 'course' => 'sample']);
        $queries = $core->getQueries();
        $queries->expects($this->never())->method('getAllUsers');
        $queries->expects($this->never())->method('insertSubmittyUser');
        $queries->expects($this->never())->method('insertCourseUser');
        $queries->expects($this->never())->method('updateUser');
        $queries->expects($this->never())->method('updateUserContactInfo');
        $core->expects($this->once())->method('addErrorMessage')->with('Invalid CSV encoding. Expected apostrophe-v1 in every row.');
        $core->expects($this->once())->method('redirect')->willThrowException(new \RuntimeException('Invalid encoding rejected'));
        $this->expectExceptionMessage('Invalid encoding rejected');
        $this->uploadCSV(new UsersController($core), "User ID,CSV Encoding\nstudent,apostrophe-v1\nother,apostrophe-v2\n", 'classlist');
    }

    private function uploadCSV(UsersController $controller, string $csv, string $list_type): void {
        $this->getFunctionMock('app\controllers\admin', 'mime_content_type')->expects($this->once())->willReturn('text/csv');
        $this->getFunctionMock('app\controllers\admin', 'file_get_contents')->expects($this->once())->willReturn($csv);
        $this->getFunctionMock('app\controllers\admin', 'register_shutdown_function')->expects($this->once());
        $saved_files = $_FILES;
        $saved_post = $_POST;
        try {
            $_FILES['upload'] = ['name' => 'roster.csv', 'tmp_name' => '/unused/roster.csv'];
            $_POST = [];
            $controller->uploadUserList($list_type);
        }
        finally {
            $_FILES = $saved_files;
            $_POST = $saved_post;
        }
    }
}
