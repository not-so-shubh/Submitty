<?php

namespace tests\app\controllers\admin;

use app\controllers\admin\ReportController;
use app\libraries\database\AbstractDatabase;
use app\libraries\database\DatabaseQueries;
use app\libraries\database\SqliteDatabase;
use app\libraries\GradeableType;
use app\libraries\response\JsonResponse;
use app\models\gradeable\Gradeable;
use app\models\gradeable\LateDays;
use app\models\User;
use tests\BaseUnitTest;
use app\libraries\FileUtils;

class ReportControllerTester extends BaseUnitTest {
    use \phpmock\phpunit\PHPMock;

    private $tmp_dir;
    private $controller;
    private $course_path;
    private $rainbow_dir;
    private $gradeables;
    private $core;

    protected function setUp(): void {
        // Prepare the mock course configurations and directories for Rainbow Grades
        $this->tmp_dir = sys_get_temp_dir() . '/submitty_test_' . uniqid();
        $this->course_path = $this->tmp_dir . '/course';
        $this->rainbow_dir = $this->course_path . '/rainbow_grades';
        FileUtils::createDir($this->rainbow_dir, true);
        FileUtils::createDir($this->course_path . '/reports/all_grades', true);
    }

    public function testStudentSummaryContactInfo(): void {
        $this->setupMockConfigs();
        $user = new User($this->core);
        $user->setId('student');
        $user->setLegalGivenName('Student');
        $user->setLegalFamilyName('Example');
        $user->setLinkedinUsername('student-name');
        $user->setGithubUsername('student-code');
        $user->setPersonalWebpage('https://example.com/?a=1&b=2');
        $method = new \ReflectionMethod(ReportController::class, 'saveUserToFile');
        $late_days = $this->createMock(LateDays::class);
        foreach ([false, true] as $clear) {
            if ($clear) {
                $user->setLinkedinUsername('');
                $user->setGithubUsername('');
                $user->setPersonalWebpage('');
            }
            $method->invoke($this->controller, $this->tmp_dir, $user, [], $late_days);
            $data = json_decode(file_get_contents($this->tmp_dir . '/student_summary.json'), true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame('student', $data['user_id']);
            $this->assertSame('Student', $data['legal_given_name']);
            $this->assertArrayHasKey('last_update', $data);
            $this->assertSame($clear ? '' : 'student-name', $data['linkedin_username']);
            $this->assertSame($clear ? '' : 'student-code', $data['github_username']);
            $this->assertSame($clear ? '' : 'https://example.com/?a=1&b=2', $data['personal_webpage']);
        }
    }

    public static function contactInfoCourseProvider(): array {
        return [
            'non-team course' => [false],
            'mixed course' => [true]
        ];
    }

    /**
     * @dataProvider contactInfoCourseProvider
     */
    public function testStudentSummaryContactInfoFromQuery(bool $mixed): void {
        $this->gradeables = [$this->createMockGradeable('individual', 'Individual homework')];
        if ($mixed) {
            $this->gradeables[] = $this->createMockGradeable('team', 'Team homework');
        }
        foreach ($this->gradeables as $gradeable) {
            $gradeable->method('isTeamAssignment')->willReturn($gradeable->getId() === 'team');
            $gradeable->method('getType')->willReturn(GradeableType::ELECTRONIC_FILE);
            $gradeable->method('getSubmissionDueDate')->willReturn(new \DateTime('2000-01-02'));
            $gradeable->method('getLateDays')->willReturn(0);
            $gradeable->method('getComponents')->willReturn([]);
        }
        $this->setupMockConfigs();
        $this->core->getConfig()->method('getDefaultStudentLateDays')->willReturn(0);
        $queries = $this->core->getQueries();
        $queries->method('getLateDayUpdates')->willReturn([]);
        $queries->method('getLateDayCacheForUser')->willReturn([]);
        $queries->method('getAllOverriddenGrades')->willReturn([]);

        $database = new SqliteDatabase(['memory' => true]);
        $database->connect(false);
        $user = [
            'user_id' => 'fictional_student', 'user_numeric_id' => '13511', 'g_anon' => 'fictional_anon',
            'user_givenname' => 'Fictional', 'user_preferred_givenname' => null,
            'user_familyname' => 'Student', 'user_preferred_familyname' => null,
            'user_pronouns' => '', 'display_pronouns' => false,
            'user_email' => 'student@example.com', 'user_email_secondary' => '',
            'user_email_secondary_notify' => false, 'user_group' => User::GROUP_STUDENT,
            'manual_registration' => false, 'last_updated' => null,
            'grading_registration_sections' => null, 'registration_section' => '1',
            'course_section_id' => 'fictional', 'rotating_section' => null,
            'registration_type' => 'graded', 'date_registered' => null, 'registration_subsection' => null,
            'linkedin_username' => 'fictional-student', 'github_username' => 'fictional-code',
            'personal_webpage' => 'https://example.com/portfolio?a=1&b=2'
        ];
        $database->query('CREATE TABLE users (' . implode(' TEXT, ', array_keys($user)) . ' TEXT)');
        $database->query('INSERT INTO users VALUES (' . implode(',', array_fill(0, count($user), '?')) . ')', array_values($user));

        $course_db = $this->createMock(AbstractDatabase::class);
        $this->core->method('getCourseDB')->willReturn($course_db);
        $course_db->method('convertBoolean')->willReturnCallback(fn($value) => $value ? 'TRUE' : 'FALSE');
        $course_db->expects($this->exactly($mixed ? 4 : 2))->method('queryIterator')
            ->willReturnCallback(function ($sql, $params, $hydrate) use ($database) {
                $team = str_contains($sql, 'team.array_team_users');
                $this->assertSame([$team ? 'team' : 'individual'], $params);
                $row = [
                    'g_id' => $params[0], 'ag_graders' => '[]', 'ag_timestamps' => '[]', 'ag_graders_names' => '[]',
                    'active_version' => 1, 'array_version' => '[1]',
                    'array_non_hidden_non_extra_credit' => $team ? '[6]' : '[8]',
                    'array_non_hidden_extra_credit' => '[0]', 'array_hidden_non_extra_credit' => '[0]',
                    'array_hidden_extra_credit' => '[0]', 'array_submission_time' => '["2000-01-01 12:00:00"]',
                    'array_autograding_complete' => '[true]'
                ];
                if ($team) {
                    $database->query('SELECT *, 1 AS state FROM users');
                    $row += [
                        'team_id' => 'fictional_team', 'team_name' => 'Fictional team',
                        'registration_section' => '1', 'rotating_section' => null,
                        'array_team_users' => json_encode($database->rows(), JSON_THROW_ON_ERROR)
                    ];
                }
                else {
                    // Execute the production outer user projection, not a hand-populated result.
                    // PostgreSQL's grade/JSON aggregation joins are outside this SQLite fixture.
                    $select = substr($sql, 0, strpos($sql, 'FROM gradeable g'));
                    preg_match_all('/\bu\.[a-z_]+\b/', $select, $columns);
                    $this->assertNotEmpty($columns[0]);
                    $database->query('SELECT ' . implode(', ', $columns[0]) . ' FROM users u');
                    $row += $database->row();
                }
                return new \ArrayIterator([$hydrate($row)]);
            });
        $real_queries = new DatabaseQueries($this->core);
        $queries->method('getGradedGradeables')->willReturnCallback([$real_queries, 'getGradedGradeables']);
        $queries->expects($this->never())->method('getUserById');

        $generate = new \ReflectionMethod(ReportController::class, 'generateReportInternal');
        $save = new \ReflectionMethod(ReportController::class, 'saveUserToFile');
        foreach ([false, true] as $clear) {
            if ($clear) {
                $database->query('UPDATE users SET linkedin_username=?, github_username=?, personal_webpage=?', ['', '', '']);
            }
            $generate->invoke($this->controller, null, ['user_id'], function ($student, $grades, $late_days) use ($save) {
                $save->invoke($this->controller, $this->tmp_dir, $student, $grades, $late_days);
            });
            $data = json_decode(file_get_contents($this->tmp_dir . '/fictional_student_summary.json'), true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame('fictional_student', $data['user_id']);
            $this->assertSame('13511', $data['user_numeric_id']);
            $this->assertSame('Fictional', $data['legal_given_name']);
            $this->assertSame('Student', $data['legal_family_name']);
            $this->assertSame('1', $data['registration_section']);
            $this->assertSame('individual', $data['Homework'][0]['id']);
            $this->assertEquals(8.0, $data['Homework'][0]['score']);
            $this->assertCount($mixed ? 2 : 1, $data['Homework']);
            if ($mixed) {
                $this->assertSame('team', $data['Homework'][1]['id']);
                $this->assertEquals(6.0, $data['Homework'][1]['score']);
                $this->assertSame(['fictional_student'], $data['Homework'][1]['team_members']);
            }
            $this->assertSame($clear ? '' : 'fictional-student', $data['linkedin_username']);
            $this->assertSame($clear ? '' : 'fictional-code', $data['github_username']);
            $this->assertSame($clear ? '' : 'https://example.com/portfolio?a=1&b=2', $data['personal_webpage']);
        }
    }

    private function setupMockConfigs() {
        // Mock the core application properties, user configurations, and database queries for the ReportController
        $config = [
            'course_path' => $this->course_path,
            'semester' => 'f25',
            'course' => 'sample',
            'base_url' => 'http://localhost',
            'use_mock_time' => true,
        ];
        $user_config = [
            'access_admin' => true,
            'user_timezone' => 'America/New_York'
        ];
        $this->gradeables = $this->gradeables ?? [
            $this->createMockGradeable('hw1', 'Homework 1', 'homework', 10),
            $this->createMockGradeable('hw2', 'Homework 2', 'homework', 20),
            $this->createMockGradeable('exam1', 'Exam 1', 'exam', 100),
        ];
        $queries = [
            'getGradeableConfigs' => $this->gradeables,
            'getRegistrationSections' => [
                ['sections_registration_id' => '1'],
                ['sections_registration_id' => '2'],
            ],
            'getAllUsers' => [],
            'getAllGradeablesIdsAndTitles' => [],
            'checkIsInstructorInCourse' => true,
        ];
        $this->core = $this->createMockCore($config, $user_config, $queries);
        $this->controller = new ReportController($this->core);
    }

    private function getSampleCustomizationJson() {
        return [
            'section' => [
                '1' => '1',
                '2' => '2',
            ],
            'omit_section_from_stats' => ['1'],
            'display_benchmark' => ['average', 'lowest_a-', 'lowest_b-', 'lowest_c-', 'lowest_d'],
            'messages' => ['Instructor Message'],
            'display' => ['grade_summary', 'grade_details', 'final_cutoff'],
            'benchmark_percent' => ['lowest_a-' => 0.9, 'lowest_b-' => 0.8, 'lowest_c-' => 0.7, 'lowest_d' => 0.6, 'average' => 0.5],
            'final_cutoff' => ['A' => 93, 'A-' => 90, 'B+' => 87, 'B' => 83, 'B-' => 80, 'C+' => 77, 'C' => 73, 'C-' => 70, 'D+' => 67, 'D' => 60],
            'gradeables' => [
                [
                    "type" => "homework",
                    "count" => 2,
                    "remove_lowest" => 0,
                    "percent" => 0.25,
                    'ids' => [
                        [
                            'max' => 10,
                            'release_date' => '9998-12-31 23:59:59-0500',
                            'id' => 'hw1',
                            'percent' => 0.50,
                            'curve' => [10, 9, 8, 6]
                        ],
                        [
                            'max' => 20,
                            'release_date' => '9998-12-31 23:59:59-0500',
                            'id' => 'hw2',
                            'percent' => 0.50
                        ],
                    ]
                ],
                [
                    'type' => 'exam',
                    'count' => 3,
                    'remove_lowest' => 0,
                    'percent' => 0.75,
                    'ids' => [
                        [
                            'max' => 100,
                            'release_date' => '9998-12-31 23:59:59-0500',
                            'id' => 'exam1',
                            'curve' => [85, 75, 65, 55]
                        ]
                    ]
                ]
            ],
            'plagiarism' => [
                [
                    'user' => 'aphacker',
                    'gradeable' => 'grades_released_homework_onlyauto',
                    'penalty' => 1
                ]
            ],
            'manual_grade' => [
                [
                    'user' => 'aphacker',
                    'grade' => 'A',
                    'note' => 'Manual Final Grade Override'
                ]
            ],
            'warning' => [
                [
                    'msg' => 'Warning Message',
                    'ids' => ['grades_released_homework_onlyauto'],
                    'value' => 10
                ]
            ],
            'extra_credit' => false,
            'show_gradeable_configuration' => false,
            'customize_show_notes' => false,
        ];
    }

    private function readCustomization($file = 'gui_customization.json') {
        return file_exists($this->rainbow_dir . '/' . $file) ? json_decode(file_get_contents($this->rainbow_dir . '/' . $file), true) : [];
    }

    private function writeCustomization($content, $file = 'gui_customization.json') {
        file_put_contents($this->rainbow_dir . '/' . $file, json_encode($content, JSON_PRETTY_PRINT));
    }

    private function verifyCustomizationUpdates($content) {
        $expected_json = json_encode($content, JSON_PRETTY_PRINT);
        $final_json = json_encode($this->readCustomization('gui_customization.json'), JSON_PRETTY_PRINT);
        $this->assertEquals($expected_json, $final_json);
    }

    private function submitCustomization($content, $expected_status = 'success') {
        $this->setupMockConfigs();
        $response = $this->controller->saveGUICustomizations();
        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals($expected_status, $response->json['status']);

        if ($expected_status == 'success') {
            $this->assertNull($response->json['data']);
            $this->verifyCustomizationUpdates($content);
        }
        else {
            $this->assertSame('Manual customization is currently in use.', $response->json['message']);
        }

        return $response;
    }

    private function createMockGradeable($id = 'test', $title = 'Test Gradeable', $bucket = 'homework', $points = 10, $date = '9998-12-31 23:59:59-0500') {
        // Mock all gradeable methods required to construct the customization data
        $gradeable = $this->createMockModel(Gradeable::class);
        $gradeable->method('getId')->willReturn($id);
        $gradeable->method('getTitle')->willReturn($title);
        $gradeable->method('hasReleaseDate')->willReturn(true);
        $gradeable->method('getSyllabusBucket')->willReturn($bucket);
        $gradeable->method('getManualGradingPoints')->willReturn($points);
        $gradeable->method('hasAutogradingConfig')->willReturn(false);
        $gradeable->method('getGradeReleasedDate')->willReturn(new \DateTime($date));
        $gradeable->method('getSubmissionOpenDate')->willReturn(new \DateTime($date));
        return $gradeable;
    }

    public function testExistingManualCustomization() {
        // Ensure the manual and main customization files are the same to imply manual customization applications
        $content = $this->getSampleCustomizationJson();
        $this->writeCustomization($content, 'customization.json');
        $this->writeCustomization($content, 'manual_customization.json');
        $this->submitCustomization($content, 'error');
    }

    public function testGUICustomizationSave() {
        // Set the existing GUI customization file
        $content = $this->getSampleCustomizationJson();
        $this->writeCustomization($content, 'gui_customization.json');
        $this->writeCustomization($content, 'customization.json');

        // Test no modifications in the customization content
        $this->submitCustomization($content);

        // Test the addition of an exam gradeable
        $this->gradeables[] = $this->createMockGradeable('exam2', 'Exam 2', 'exam', 100);
        $content['gradeables'][1]['ids'][] = [
            'max' => 100,
            'release_date' => '9998-12-31 23:59:59-0500',
            'id' => 'exam2',
        ];
        $this->submitCustomization($content);

        // Test the update of the release date of the exam gradeable
        array_pop($this->gradeables); // Replace the mock gradeable
        $this->gradeables[] = $this->createMockGradeable('exam2', 'Exam 2', 'exam', 100, '2025-01-01 23:59:59-0500');
        $content['gradeables'][1]['ids'][1]['release_date'] = '2025-01-01 23:59:59-0500';
        $this->submitCustomization($content);

        // Test removal of the new gradeable
        array_pop($this->gradeables);
        array_pop($content['gradeables'][1]['ids']);
        $this->submitCustomization($content);

        // Test the swapping of a gradeable bucket
        array_pop($this->gradeables); // Remove the original exam gradeable
        $this->gradeables[] = $this->createMockGradeable('exam1', 'Exam 1', 'homework', 100, '2025-01-01 23:59:59-0500');
        $content['gradeables'][0]['count'] = 3; // Increment in count should only be possible to handle "future gradeable" configurations
        $content['gradeables'][1]['ids'] = []; // No existing exam gradeables
        $content['gradeables'][0]['ids'][] = [
            'max' => 100,
            'release_date' => '2025-01-01 23:59:59-0500',
            'id' => 'exam1'
        ];
        $this->submitCustomization($content);

        // Test the addition of a gradeable in an unused bucket, leading to no changes
        $this->gradeables[] = $this->createMockGradeable('lab1', 'Lab 1', 'lab', 100, '2025-01-01 23:59:59-0500');
        $this->submitCustomization($content);
    }

    public function testGenerateCustomizationShowsNormalizationWarning() {
        // Write a gui_customization.json with a legacy bucket containing "ids": null
        $content = $this->getSampleCustomizationJson();
        $content['gradeables'][] = [
            'type' => 'legacy-bucket',
            'count' => 1,
            'remove_lowest' => 0,
            'percent' => 0.25,
            'ids' => null
        ];
        $this->writeCustomization($content, 'gui_customization.json');
        $this->writeCustomization($content, 'customization.json');

        $this->setupMockConfigs();
        $response = $this->controller->saveGUICustomizations();
        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals('success', $response->json['status']);
    }
}
