<?php
/**
 * 年齡別運動基準設定
 * 
 * 依據世界衛生組織 (WHO) 及衛福部國民健康署建議標準
 * 
 * 參考資料：
 * - WHO Guidelines on Physical Activity and Sedentary Behaviour (2020)
 *   https://www.who.int/publications/i/item/9789240015128
 * - 衛福部國民健康署 - 身體活動指引
 *   https://www.hpa.gov.tw/Pages/List.aspx?nodeid=571
 * 
 * 運動建議標準:
 * - 兒童青少年 (6-17歲): 每天至少60分鐘中高強度運動
 * - 成人 (18-64歲): 每週至少150分鐘中等強度運動
 * - 長者 (65歲以上): 每週至少150分鐘中等強度運動，並增加平衡訓練
 */

// 各年齡層每日建議標準
// 獲得相同點數所需的運動量會依年齡調整
// 年輕人需要較多運動量獲得相同點數，長者則較容易達標

$AGE_STANDARDS = [
    // 兒童青少年 (6-17歲) - 需要較高運動量
    'youth' => [
        'min_age' => 6,
        'max_age' => 17,
        'label' => '兒童青少年',
        'daily_steps_target' => 12000,      // 每日步數目標
        'daily_exercise_minutes' => 60,     // 每日運動分鐘目標
        'daily_water_ml' => 1800,           // 每日飲水目標 (ml)
        // 點數計算基準 (要達到這個值才能獲得基本點數)
        'points_per_steps' => 1500,         // 每 X 步 = 2 點
        'points_per_minutes' => 30,         // 每 X 分鐘 = 3 點
        'points_per_water' => 600,          // 每 X ml = 1 點
    ],
    
    // 成人 (18-64歲) - 標準運動量
    'adult' => [
        'min_age' => 18,
        'max_age' => 64,
        'label' => '成人',
        'daily_steps_target' => 8000,       // 每日步數目標
        'daily_exercise_minutes' => 30,     // 每日運動分鐘目標
        'daily_water_ml' => 2000,           // 每日飲水目標 (ml)
        // 點數計算基準
        'points_per_steps' => 1000,         // 每 X 步 = 2 點
        'points_per_minutes' => 30,         // 每 X 分鐘 = 3 點
        'points_per_water' => 500,          // 每 X ml = 1 點
    ],
    
    // 長者 (65歲以上) - 較低運動量但同樣重要
    'senior' => [
        'min_age' => 65,
        'max_age' => 999,
        'label' => '長者',
        'daily_steps_target' => 6000,       // 每日步數目標
        'daily_exercise_minutes' => 20,     // 每日運動分鐘目標
        'daily_water_ml' => 1500,           // 每日飲水目標 (ml)
        // 點數計算基準 (較容易達標)
        'points_per_steps' => 800,          // 每 X 步 = 2 點
        'points_per_minutes' => 20,         // 每 X 分鐘 = 3 點
        'points_per_water' => 400,          // 每 X ml = 1 點
    ],
];

// 預設標準（未設定生日時使用成人標準）
$DEFAULT_STANDARD = 'adult';

/**
 * 根據出生日期計算年齡
 * @param string|null $birth_date 出生日期 (YYYY-MM-DD 格式)
 * @return int|null 年齡（歲），若未提供則返回 null
 */
function calculate_age($birth_date) {
    if (empty($birth_date)) {
        return null;
    }
    
    try {
        $birth = new DateTime($birth_date);
        $today = new DateTime('today');
        $age = $birth->diff($today)->y;
        return $age;
    } catch (Exception $e) {
        return null;
    }
}

/**
 * 根據年齡取得對應的運動標準
 * @param int|null $age 年齡
 * @return array 運動標準配置
 */
function get_age_standard($age) {
    global $AGE_STANDARDS, $DEFAULT_STANDARD;
    
    // 未提供年齡時使用預設標準
    if ($age === null) {
        return $AGE_STANDARDS[$DEFAULT_STANDARD];
    }
    
    // 根據年齡找到對應的標準
    foreach ($AGE_STANDARDS as $key => $standard) {
        if ($age >= $standard['min_age'] && $age <= $standard['max_age']) {
            return $standard;
        }
    }
    
    // 如果都不符合，使用預設標準
    return $AGE_STANDARDS[$DEFAULT_STANDARD];
}

/**
 * 根據年齡計算運動點數
 * @param int|null $age 年齡
 * @param int $steps 步數
 * @param int $time_minutes 運動分鐘
 * @param int $water_ml 飲水量 (ml)
 * @return array 包含總點數和各項目點數的陣列
 */
function calculate_points_by_age($age, $steps, $time_minutes, $water_ml) {
    $standard = get_age_standard($age);
    
    // 根據年齡標準計算點數
    $steps_points = floor($steps / $standard['points_per_steps']) * 2;
    $time_points = floor($time_minutes / $standard['points_per_minutes']) * 3;
    $water_points = floor($water_ml / $standard['points_per_water']) * 1;
    
    $total_points = max(0, $steps_points + $time_points + $water_points);
    
    return [
        'total' => $total_points,
        'steps_points' => $steps_points,
        'time_points' => $time_points,
        'water_points' => $water_points,
        'standard_used' => $standard['label'],
        'age' => $age
    ];
}

/**
 * 取得運動建議資訊（用於顯示給使用者）
 * @param int|null $age 年齡
 * @return array 運動建議資訊
 */
function get_exercise_recommendations($age) {
    $standard = get_age_standard($age);
    
    return [
        'age_group' => $standard['label'],
        'daily_steps' => $standard['daily_steps_target'],
        'daily_exercise' => $standard['daily_exercise_minutes'],
        'daily_water' => $standard['daily_water_ml'],
        'description' => sprintf(
            '建議每日步行 %s 步、運動 %d 分鐘、飲水 %d ml',
            number_format($standard['daily_steps_target']),
            $standard['daily_exercise_minutes'],
            $standard['daily_water_ml']
        )
    ];
}
