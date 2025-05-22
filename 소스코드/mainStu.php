<?php
header('Content-Type: text/html; charset=UTF-8'); // UTF-8 인코딩 설정

session_start();

// 사용자가 로그인되어 있고 학생인지 확인
if (!isset($_SESSION["userID"]) || $_SESSION["userRole"] !== 'student')
{
    // 로그인 페이지로 리다이렉트
    header("Location: login.php");
    exit();
}

// DB 연결
$conn = new mysqli("localhost", "dbproject_user", "Gkrrytlfj@@33", "dbproject");
if ($conn->connect_error) die("DB 연결 실패: " . $conn->connect_error);
$conn->set_charset("utf8");

// 학생 정보 가져오기
$studentID = $_SESSION["userID"];
$studentQuery = "SELECT u.userID, u.userName, u.grade, u.lastSemesterCredits, u.userRole, 
                        u.departmentID, d.departmentName, c.collegeName 
                FROM User u 
                LEFT JOIN Department d ON u.departmentID = d.departmentID
                LEFT JOIN College c ON d.collegeID = c.collegeID
                WHERE u.userID = ?";

$stmt = $conn->prepare($studentQuery);
$stmt->bind_param("s", $studentID);
$stmt->execute();
$studentResult = $stmt->get_result();
$studentInfo = $studentResult->fetch_assoc();
$stmt->close();

// 수강신청 내역 가져오기 (중복 제거를 위해 CourseTime을 별도로 처리)
$enrolledQuery = "SELECT e.*, c.courseName, c.credits, u.userName as professor, c.creditType
                FROM Enroll e
                JOIN Course c ON e.courseID = c.courseID
                LEFT JOIN User u ON c.professorID = u.userID
                WHERE e.userID = ?";
$stmt = $conn->prepare($enrolledQuery);
$stmt->bind_param("s", $studentID);
$stmt->execute();
$enrolledCourses = $stmt->get_result();
$stmt->close();

// CourseTime 데이터를 별도로 가져오기
$courseTimesQuery = "SELECT ct.courseID, ct.dayOfWeek, ct.startPeriod, ct.endPeriod
                     FROM CourseTime ct
                     WHERE ct.courseID IN (SELECT courseID FROM Enroll WHERE userID = ?)";
$stmt = $conn->prepare($courseTimesQuery);
$stmt->bind_param("s", $studentID);
$stmt->execute();
$courseTimesResult = $stmt->get_result();
$stmt->close();

// CourseTime 데이터를 courseID별로 그룹화
$courseTimes = [];
while ($time = $courseTimesResult->fetch_assoc()) {
    $courseTimes[$time['courseID']][] = $time;
}

// 총 학점 및 시간표 데이터 초기화
$totalCredits = 0;
$totalCourses = 0;
$timeTable = array(); // 시간표 데이터를 저장하기 위한 배열

// 강의별 색상 생성을 위한 배열
$courseColors = array();

if ($enrolledCourses->num_rows > 0) {
    $totalCourses = $enrolledCourses->num_rows;
    
    while ($course = $enrolledCourses->fetch_assoc()) {
        $totalCredits += $course['credits'];

        // courseID를 기반으로 고유한 색상 생성
        if (!isset($courseColors[$course['courseID']])) {
            // courseID를 해시하여 Hue 값 생성 (0~360)
            $hash = crc32($course['courseID']);
            $hue = $hash % 360; // 0~359 사이의 Hue 값
            $saturation = 60; // 채도 60%
            $lightness = 50;  // 밝기 50% (가독성을 위해 너무 어둡거나 밝지 않게)
            $courseColors[$course['courseID']] = "hsl($hue, $saturation%, $lightness%)";
        }

        // 시간표 데이터 저장 (A, B 구분 포함)
        if (isset($courseTimes[$course['courseID']])) {
            foreach ($courseTimes[$course['courseID']] as $time) {
                $day = $time['dayOfWeek'];
                $start = $time['startPeriod'];
                $end = $time['endPeriod'];

                // A/B가 포함된 경우와 숫자만 있는 경우 구분
                $startNum = (int)preg_replace('/[AB]/', '', $start);
                $endNum = (int)preg_replace('/[AB]/', '', $end);
                $startAB = preg_match('/[AB]/', $start) ? substr($start, -1) : '';
                $endAB = preg_match('/[AB]/', $end) ? substr($end, -1) : '';

                if ($startAB === '' && $endAB === '') {
                    // 숫자만 있는 경우: A와 B 모두 표시
                    for ($period = $startNum; $period <= $endNum; $period++) {
                        $timeTable[$day][$period]['A'] = [
                            'courseName' => $course['courseName'],
                            'courseID' => $course['courseID']
                        ];
                        $timeTable[$day][$period]['B'] = [
                            'courseName' => $course['courseName'],
                            'courseID' => $course['courseID']
                        ];
                    }
                } else {
                    // A/B가 명시된 경우: startAB에서 endAB까지 표시
                    for ($period = $startNum; $period <= $endNum; $period++) {
                        if ($period == $startNum) {
                            // 시작 교시: startAB에 따라 A 또는 B 시작
                            if ($startAB == 'A' || $startAB == '') {
                                $timeTable[$day][$period]['A'] = [
                                    'courseName' => $course['courseName'],
                                    'courseID' => $course['courseID']
                                ];
                            }
                            if ($startAB == 'B' || $startAB == '' || $startAB == 'A') {
                                $timeTable[$day][$period]['B'] = [
                                    'courseName' => $course['courseName'],
                                    'courseID' => $course['courseID']
                                ];
                            }
                        } elseif ($period == $endNum) {
                            // 종료 교시: endAB에 따라 A 또는 B 끝
                            if ($endAB == 'A' || $endAB == '') {
                                $timeTable[$day][$period]['A'] = [
                                    'courseName' => $course['courseName'],
                                    'courseID' => $course['courseID']
                                ];
                            }
                            if ($endAB == 'B' || $endAB == '') {
                                $timeTable[$day][$period]['B'] = [
                                    'courseName' => $course['courseName'],
                                    'courseID' => $course['courseID']
                                ];
                            }
                            // 종료 교시에서도 A를 포함 (B-B 구간에서 중간 교시처럼 처리)
                            if ($endAB == 'B' && $startAB == 'B' && $period > $startNum) {
                                $timeTable[$day][$period]['A'] = [
                                    'courseName' => $course['courseName'],
                                    'courseID' => $course['courseID']
                                ];
                            }
                        } else {
                            // 중간 교시: A와 B 모두 표시
                            $timeTable[$day][$period]['A'] = [
                                'courseName' => $course['courseName'],
                                'courseID' => $course['courseID']
                            ];
                            $timeTable[$day][$period]['B'] = [
                                'courseName' => $course['courseName'],
                                'courseID' => $course['courseID']
                            ];
                        }
                    }
                }
            }
        }
    }
    
    // 쿼리 결과를 다시 얻기 위해 쿼리를 재실행
    $stmt = $conn->prepare($enrolledQuery);
    $stmt->bind_param("s", $studentID);
    $stmt->execute();
    $enrolledCourses = $stmt->get_result();
    $stmt->close();
}

// 한 학기 최대 신청 가능 학점 - lastSemesterCredits에 따라 다르게 설정
$maxCredits = ($studentInfo['lastSemesterCredits'] >= 3.0) ? 19 : 18;

// 검색 드롭다운을 위한 모든 단과대학 조회
$collegesQuery = "SELECT * FROM College ORDER BY collegeName";
$colleges = $conn->query($collegesQuery);

// 검색 드롭다운을 위한 모든 학과 조회
$departmentsQuery = "SELECT d.*, c.collegeName FROM Department d 
                     JOIN College c ON d.collegeID = c.collegeID 
                     ORDER BY c.collegeName, d.departmentName";
$departments = $conn->query($departmentsQuery);

// 요일 매핑
$daysKorean = [
    'Mon' => '월',
    'Tue' => '화',
    'Wed' => '수',
    'Thu' => '목',
    'Fri' => '금',
    'Sat' => '토'
];

$daysEnglish = [
    '월' => 'Mon',
    '화' => 'Tue',
    '수' => 'Wed',
    '목' => 'Thu',
    '금' => 'Fri',
    '토' => 'Sat'
];

// 검색 결과 초기화
$searchResults = null;

// 검색 기능
if (isset($_GET['search']) && $_GET['search'] == '1') {
    $searchQuery = "SELECT c.*, u.userName as professor, d.departmentName, ct.dayOfWeek, ct.startPeriod, ct.endPeriod 
                   FROM Course c 
                   LEFT JOIN User u ON c.professorID = u.userID
                   LEFT JOIN Department d ON c.departmentID = d.departmentID
                   LEFT JOIN CourseTime ct ON c.courseID = ct.courseID
                   WHERE 1=1";
    
    $params = [];
    $types = "";
    
    // 강의구분(교과구분)로 필터 (creditType)
    if (!empty($_GET['courseType'])) {
        $searchQuery .= " AND c.creditType = ?";
        $params[] = $_GET['courseType'];
        $types .= "s";
    }
    
    // 단과대학(College)로 필터
    if (!empty($_GET['college'])) {
        $searchQuery .= " AND d.collegeID = ?";
        $params[] = $_GET['college'];
        $types .= "i";
    }

    // 학과(Department)로 필터
    if (!empty($_GET['department'])) {
        $searchQuery .= " AND c.departmentID = ?";
        $params[] = $_GET['department'];
        $types .= "i";
    }
    
    // 과목명 또는 교수명으로 필터
    if (!empty($_GET['keyword'])) {
        $keyword = '%' . $_GET['keyword'] . '%';
        $searchQuery .= " AND (c.courseName LIKE ? OR u.userName LIKE ?)";
        $params[] = $keyword;
        $params[] = $keyword;
        $types .= "ss";
    }
    
    $searchQuery .= " GROUP BY c.courseID ORDER BY c.courseID";
    
    $stmt = $conn->prepare($searchQuery);
    
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    
    $stmt->execute();
    $searchResults = $stmt->get_result();
    $stmt->close();
}
?>

<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>순천향대학교 수강신청 시스템 - 학생</title>
    <style>
        * 
        {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Malgun Gothic', sans-serif;
        }

        body 
        {
            background-color: #f5f5f5;
        }

        .section 
        {
            width: 90%;
            max-width: 1200px;
            margin: 0 auto;
            background-color: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            margin-top: 30px;
            margin-bottom: 30px;
        }

        .logo 
        {
            text-align: center;
            margin-bottom: 20px;
        }

        .logo img 
        {
            width: 200px;
        }

        .header 
        {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 1px solid #eee;
        }

        .studentInfo 
        {
            font-size: 14px;
            color: #666;
        }

        .logoutButton 
        {
            padding: 8px 15px;
            background-color: #f2f2f2;
            color: #333;
            text-decoration: none;
            border-radius: 5px;
            font-size: 14px;
        }

        .logoutButton:hover 
        {
            background-color: #e0e0e0;
        }

        .creditInfo 
        {
            display: flex;
            justify-content: space-between;
            margin-bottom: 20px;
            padding: 15px;
            background-color: #f9f9f9;
            border-radius: 5px;
            border: 1px solid #eee;
        }

        .creditBox 
        {
            text-align: center;
            flex: 1;
        }

        .creditBox h3 
        {
            font-size: 14px;
            color: #555;
            margin-bottom: 5px;
        }

        .creditBox p 
        {
            font-size: 20px;
            color: #00a8ff;
            font-weight: bold;
        }

        .creditBox .maxCredit 
        {
            color: #ff6b6b;
        }

        .contentWrapper 
        {
            display: flex;
            gap: 20px;
            margin-bottom: 20px;
        }

        .courseList 
        {
            flex: 2;
        }

        .timeTable 
        {
            flex: 1;
            border: 1px solid #ddd;
            border-radius: 5px;
            overflow: hidden;
        }

        .timeTable table 
        {
            width: 100%;
            border-collapse: collapse;
        }

        .timeTable th, .timeTable td
        {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: center;
            font-size: 12px;
            height: 25px;
        }

        .timeTable th 
        {
            background-color: #f2f2f2;
        }

        .timeTable .time 
        {
            width: 30px;
            background-color: #f2f2f2;
            font-weight: bold;
        }

        .timeTable .course 
        {
            color: white;
            font-size: 11px;
        }

        /* 동적으로 생성된 강의 색상 클래스 */
        <?php
        foreach ($courseColors as $courseID => $color) {
            echo ".course-$courseID { background-color: $color; }\n";
        }
        ?>

        .searchSection 
        {
            background-color: #f9f9f9;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
            border: 1px solid #eee;
        }

        .searchRow 
        {
            display: flex;
            gap: 10px;
            margin-bottom: 10px;
            align-items: center;
        }

        .searchRow label 
        {
            width: 120px;
            font-size: 14px;
            color: #555;
        }

        .searchRow select, .searchRow input 
        {
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 5px;
            flex: 1;
        }

        .buttonRow 
        {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 10px;
        }

        .button 
        {
            padding: 8px 20px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
        }

        .searchButton 
        {
            background-color: #00a8ff;
            color: white;
        }

        .searchButton:hover 
        {
            background-color: #0090dd;
        }

        .resetButton 
        {
            background-color: #f2f2f2;
            color: #333;
        }

        .resetButton:hover 
        {
            background-color: #e0e0e0;
        }

        table 
        {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
            font-size: 14px;
        }

        table caption 
        {
            font-size: 16px;
            font-weight: bold;
            margin-bottom: 10px;
            text-align: left;
            color: #333;
        }

        th, td 
        {
            border: 1px solid #ddd;
            padding: 10px;
            text-align: center;
        }

        th 
        {
            background-color: #f2f2f2;
            color: #333;
            font-weight: bold;
        }

        tr:nth-child(even) 
        {
            background-color: #f9f9f9;
        }

        tr:hover 
        {
            background-color: #f0f7ff;
        }

        .courseType 
        {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 3px;
            color: white;
            font-size: 12px;
            font-weight: bold;
        }

        .required 
        {
            background-color: #00a8ff;
        }

        .elective 
        {
            background-color: #28a745;
        }

        .registerButton 
        {
            padding: 5px 10px;
            background-color: #00a8ff;
            color: white;
            border: none;
            border-radius: 3px;
            cursor: pointer;
            font-size: 12px;
        }

        .registerButton:hover 
        {
            background-color: #0090dd;
        }

        .deleteButton 
        {
            padding: 5px 10px;
            background-color: #ff6b6b;
            color: white;
            border: none;
            border-radius: 3px;
            cursor: pointer;
            font-size: 12px;
        }

        .deleteButton:hover 
        {
            background-color: #ff5252;
        }

        .notification 
        {
            font-size: 12px;
            color: #666;
            margin-top: 10px;
            display: flex;
            align-items: center;
            margin-bottom: 10px;
        }

        .notification img 
        {
            width: 16px;
            margin-right: 5px;
        }
    </style>
</head>
<body>
    <div class="section">
        <div class="logo">
            <img src="https://blog.kakaocdn.net/dn/bx64Eo/btqEOZOpwoE/veAdLIDj4xKXMakWfvHRmk/img.jpg" alt="순천향대학교 로고">
        </div>

        <div class="header">
            <div class="studentInfo">
                <strong><?= htmlspecialchars($studentInfo['userName']) ?></strong> 님 환영합니다
                <span>(학과: <?= htmlspecialchars($studentInfo['departmentName']) ?>, 학번: <?= htmlspecialchars($studentID) ?>)</span>
            </div>
            <a href="login.php" class="logoutButton">로그아웃</a>
        </div>

        <!-- 수강신청 현황 정보 -->
        <div class="creditInfo">
            <div class="creditBox">
                <h3>현재 신청 학점</h3>
                <p><?= $totalCredits ?> 학점</p>
            </div>
            <div class="creditBox">
                <h3>신청 교과목 수</h3>
                <p><?= $totalCourses ?> 과목</p>
            </div>
            <div class="creditBox">
                <h3>신청 가능 학점</h3>
                <p class="maxCredit"><?= $maxCredits ?> 학점</p>
            </div>
        </div>

        <!-- 수강신청 내역 및 시간표 -->
        <div class="contentWrapper">
            <div class="courseList">
                <table>
                    <thead>
                        <tr>
                            <th>No.</th>
                            <th>이수구분</th>
                            <th>과목코드</th>
                            <th>교과목명</th>
                            <th>교수명</th>
                            <th>학점</th>
                            <th>강의시간</th>
                            <th>관리</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $rowNum = 1;
                        if ($enrolledCourses->num_rows > 0) {
                            while ($course = $enrolledCourses->fetch_assoc()) { 
                                // 강의 시간 포맷팅
                                $timeSlots = [];
                                if (isset($courseTimes[$course['courseID']])) {
                                    foreach ($courseTimes[$course['courseID']] as $time) {
                                        $day = isset($daysKorean[$time['dayOfWeek']]) ? $daysKorean[$time['dayOfWeek']] : $time['dayOfWeek'];
                                        $timeSlots[] = "$day {$time['startPeriod']}-{$time['endPeriod']}";
                                    }
                                }
                                $timeDisplay = implode('/', $timeSlots);
                        ?>
                        <tr>
                            <td><?= $rowNum++ ?></td>
                            <td><?= htmlspecialchars($course['creditType']) ?></td>
                            <td><?= htmlspecialchars($course['courseID']) ?></td>
                            <td><?= htmlspecialchars($course['courseName']) ?></td>
                            <td><?= htmlspecialchars($course['professor']) ?></td>
                            <td><?= htmlspecialchars($course['credits']) ?></td>
                            <td><?= htmlspecialchars($timeDisplay) ?></td>
                            <td>
                                <form method="post" action="cancelEnrollment.php" style="display:inline;">
                                    <input type="hidden" name="courseID" value="<?= $course['courseID'] ?>">
                                    <button type="button" class="deleteButton" onclick="deleteCourse('<?= $course['courseID'] ?>')">삭제</button>
                                </form>
                            </td>
                        </tr>
                        <?php 
                            }
                        } else {
                        ?>
                        <tr>
                            <td colspan="8">수강신청 내역이 없습니다.</td>
                        </tr>
                        <?php } ?>
                    </tbody>
                </table>
            </div>
            <div class="timeTable">
                <table>
                    <thead>
                        <tr>
                            <th></th>
                            <th>Mon</th>
                            <th>Tue</th>
                            <th>Wed</th>
                            <th>Thu</th>
                            <th>Fri</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        // 시간표를 1교시부터 9교시까지 반복, 각 교시에 A와 B 추가
                        for ($i = 1; $i <= 9; $i++) { 
                            // A row
                            echo "<tr>";
                            echo "<td class='time' rowspan='2'>$i</td>";
                            foreach (array('월', '화', '수', '목', '금') as $day) {
                                $dayEng = $daysEnglish[$day];
                                echo "<td ";
                                if (isset($timeTable[$day][$i]['A'])) { 
                                    $courseID = $timeTable[$day][$i]['A']['courseID'];
                                    echo "class='course course-$courseID' title='" . 
                                        htmlspecialchars($timeTable[$day][$i]['A']['courseName'], ENT_QUOTES, 'UTF-8') . "'>";
                                    echo htmlspecialchars(mb_substr($timeTable[$day][$i]['A']['courseName'], 0, 3, 'UTF-8'), ENT_QUOTES, 'UTF-8');
                                    if (mb_strlen($timeTable[$day][$i]['A']['courseName'], 'UTF-8') > 3) echo "...";
                                } else {
                                    echo ">";
                                }
                                echo "</td>";
                            }
                            echo "</tr>";

                            // B row
                            echo "<tr>";
                            foreach (array('월', '화', '수', '목', '금') as $day) {
                                $dayEng = $daysEnglish[$day];
                                echo "<td ";
                                if (isset($timeTable[$day][$i]['B'])) { 
                                    $courseID = $timeTable[$day][$i]['B']['courseID'];
                                    echo "class='course course-$courseID' title='" . 
                                        htmlspecialchars($timeTable[$day][$i]['B']['courseName'], ENT_QUOTES, 'UTF-8') . "'>";
                                    echo htmlspecialchars(mb_substr($timeTable[$day][$i]['B']['courseName'], 0, 3, 'UTF-8'), ENT_QUOTES, 'UTF-8');
                                    if (mb_strlen($timeTable[$day][$i]['B']['courseName'], 'UTF-8') > 3) echo "...";
                                } else {
                                    echo ">";
                                }
                                echo "</td>";
                            }
                            echo "</tr>";
                        } ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- 강의 검색 -->
        <form method="get" action="<?= $_SERVER['PHP_SELF'] ?>">
            <input type="hidden" name="search" value="1">
            <div class="searchSection">
                <div class="searchRow">
                    <label for="courseType">강의구분</label>
                    <select id="courseType" name="courseType">
                        <option value="">전체</option>
                        <option value="필수" <?= isset($_GET['courseType']) && $_GET['courseType'] === '필수' ? 'selected' : '' ?>>교양필수</option>
                        <option value="선택" <?= isset($_GET['courseType']) && $_GET['courseType'] === '선택' ? 'selected' : '' ?>>교양선택</option>
                        <option value="전공" <?= isset($_GET['courseType']) && $_GET['courseType'] === '전공' ? 'selected' : '' ?>>전공</option>
                    </select>
                    
                    <label for="college">단과대학</label>
                    <select id="college" name="college">
                        <option value="">전체</option>
                        <?php while ($college = $colleges->fetch_assoc()) { ?>
                        <option value="<?= $college['collegeID'] ?>" <?= isset($_GET['college']) && $_GET['college'] == $college['collegeID'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($college['collegeName']) ?>
                        </option>
                        <?php } ?>
                    </select>
                </div>
                
                <div class="searchRow">
                    <label for="department">학과</label>
                    <select id="department" name="department">
                        <option value="">학과 선택</option>
                        <?php 
                        // 학과 드롭다운을 단과대학에 따라 필터링
                        $departments->data_seek(0);
                        while ($department = $departments->fetch_assoc()) { 
                        ?>
                        <option value="<?= $department['departmentID'] ?>" 
                                data-college="<?= $department['collegeID'] ?>"
                                <?= isset($_GET['department']) && $_GET['department'] == $department['departmentID'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($department['departmentName']) ?>
                        </option>
                        <?php } ?>
                    </select>
                    
                    <label for="keyword">검색어</label>
                    <input type="text" id="keyword" name="keyword" placeholder="과목명 또는 교수명을 입력하세요" 
                        value="<?= isset($_GET['keyword']) ? htmlspecialchars($_GET['keyword']) : '' ?>">
                </div>
                
                <div class="buttonRow">
                    <button type="submit" class="button searchButton">조회</button>
                    <button type="button" class="button resetButton" onclick="resetSearch()">초기화</button>
                </div>
            </div>
        </form>

        <div class="notification">
            <img src="data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHdpZHRoPSIxNiIgaGVpZ2h0PSIxNiIgdmlld0JveD0iMCAwIDE2IDE2IiBmaWxsPSIjOTk5Ij48cGF0aCBkPSJNOCAxQzQuMTQgMSAxIDQuMTQgMSA4QzEgMTEuODYgNC4xNCAxNSA4IDE1QzExLjg2IDE1IDE1IDExLjg2IDE1IDhDMTUgNC4xNCAxMS44NiAxIDggMU0gOCAxNkM0LjY4NiAxNiAyIDE0LjMxNCAyIDEwQzIgNS42ODYgNC42ODYgMiA4IDJDMTEuMzEzIDIgMTQgNS42ODYgMTQgMTBDMTQgMTQuMzE0IDExLjMxMyAxNiA4IDE2Ij48L3BhdGg+PHBhdGggZD0iTTcgM0g9VjlIN1YzWk0gNyAxMUg9VjEzSDdWMTFaIj48L3BhdGg+PC9zdmc+" alt="정보">
            실시간으로 수강신청 상태가 반영됩니다. 모든 신청은 시스템에 즉시 기록됩니다.
        </div>

        <!-- 강의 목록 -->
        <table>
            <caption>강의 목록</caption>
            <thead>
                <tr>
                    <th>No.</th>
                    <th>이수구분</th>
                    <th>과목코드</th>
                    <th>교과목명</th>
                    <th>학과</th>
                    <th>교수명</th>
                    <th>학점</th>
                    <th>강의시간</th>
                    <th>정원/신청</th>
                    <th>신청</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                if ($searchResults !== null && $searchResults->num_rows > 0) {
                    $rowNum = 1;
                    while ($course = $searchResults->fetch_assoc()) {
                        $courseDay = isset($daysKorean[$course['dayOfWeek']]) ? $daysKorean[$course['dayOfWeek']] : $course['dayOfWeek'];
                        
                        // Check if student is already enrolled in this course
                        $stmt = $conn->prepare("SELECT * FROM Enroll WHERE userID = ? AND courseID = ?");
                        $stmt->bind_param("ss", $studentID, $course['courseID']);
                        $stmt->execute();
                        $alreadyEnrolled = $stmt->get_result()->num_rows > 0;
                        $stmt->close();
                ?>
                <tr>
                    <td><?= $rowNum++ ?></td>
                    <td><?= htmlspecialchars($course['creditType']) ?></td>
                    <td><?= htmlspecialchars($course['courseID']) ?></td>
                    <td><?= htmlspecialchars($course['courseName']) ?></td>
                    <td><?= htmlspecialchars($course['departmentName']) ?></td>
                    <td><?= htmlspecialchars($course['professor']) ?></td>
                    <td><?= htmlspecialchars($course['credits']) ?></td>
                    <td><?= htmlspecialchars($courseDay) ?> <?= htmlspecialchars($course['startPeriod']) ?>-<?= htmlspecialchars($course['endPeriod']) ?></td>
                    <td><?= htmlspecialchars($course['capacity']) ?>/<?= htmlspecialchars($course['currentEnrollment']) ?></td>
                    <td>
                        <?php if ($alreadyEnrolled) { ?>
                            <button class="deleteButton" onclick="deleteCourse('<?= $course['courseID'] ?>')">취소</button>
                        <?php } else if ($course['currentEnrollment'] >= $course['capacity']) { ?>
                            <button class="registerButton" onclick="requestExtraEnroll('<?= $course['courseID'] ?>')">빌넣요청</button>
                        <?php } else { ?>
                            <button class="registerButton" onclick="enrollCourse('<?= $course['courseID'] ?>')">신청</button>
                        <?php } ?>
                    </td>
                </tr>
                <?php
                    }
                } else if (isset($_GET['search']) && $_GET['search'] == '1') {
                ?>
                <tr>
                    <td colspan="10">검색 결과가 없습니다.</td>
                </tr>
                <?php } else { ?>
                <tr>
                    <td colspan="10">위에서 조회 버튼을 클릭하여 강의를 검색하세요.</td>
                </tr>
                <?php } ?>
            </tbody>
        </table>
    </div>

    <script>
        // 단과대학 선택에 따라 학과 목록 변경하는 함수
        document.getElementById('college').addEventListener('change', function() {
            const collegeID = this.value;
            const departmentSelect = document.getElementById('department');
            const options = departmentSelect.options;
            
            // 첫 번째 옵션 빼고 모두 숨기기
            for (let i = 1; i < options.length; i++) {
                const option = options[i];
                if (collegeID === '' || option.getAttribute('data-college') === collegeID) {
                    option.style.display = '';
                } else {
                    option.style.display = 'none';
                }
            }
            
            // 선택 초기화
            departmentSelect.selectedIndex = 0;
        });
        
        // 검색 초기화 함수
        function resetSearch() {
            document.getElementById('courseType').value = '';
            document.getElementById('college').value = '';
            document.getElementById('department').value = '';
            document.getElementById('keyword').value = '';
            
            // 모든 학과 옵션 표시
            const departmentSelect = document.getElementById('department');
            const options = departmentSelect.options;
            for (let i = 1; i < options.length; i++) {
                options[i].style.display = '';
            }
        }
        
        // 수강신청 취소 함수
        function deleteCourse(courseID) {
            if (confirm('정말로 이 강의를 취소하시겠습니까?')) {
                // 서버에 삭제 요청 보내는
                alert('수강신청 취소 기능은 실제 구현 시 AJAX를 통해 서버에 요청하게 됩니다.');
            }
        }
    </script>
</body>
</html>