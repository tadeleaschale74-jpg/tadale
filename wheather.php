<?php
// empty
?>
<?php
// Simple Weather Detector + Recommendations
// Requirements: set your OpenWeatherMap API key in $apiKey or in env var OPENWEATHER_API_KEY

$apiKey = getenv('OPENWEATHER_API_KEY') ?: 'PUT_YOUR_API_KEY_HERE';

// Basic API-key checks
$apiKeyMissing = false;
$apiKeyMessage = '';
if(empty($apiKey) || strpos($apiKey, 'PUT_YOUR_API_KEY_HERE') !== false){
	$apiKeyMissing = true;
	$apiKeyMessage = 'OpenWeatherMap API key is not set. Set OPENWEATHER_API_KEY env var or replace PUT_YOUR_API_KEY_HERE in the script.';
}

// DB configuration: default to SQLite file 'weather.db' in this folder.
// To use MySQL/MariaDB set env DB_TYPE=mysql and DB_HOST, DB_NAME, DB_USER, DB_PASS.
function initDb(){
	$type = getenv('DB_TYPE') ?: 'sqlite';
	try{
		if(strtolower($type) === 'mysql' || strtolower($type) === 'mariadb'){
			$host = getenv('DB_HOST') ?: '127.0.0.1';
			$name = getenv('DB_NAME') ?: 'weather';
			$user = getenv('DB_USER') ?: 'root';
			$pass = getenv('DB_PASS') ?: '';
			$dsn = "mysql:host={$host};dbname={$name};charset=utf8mb4";
			$pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
		} else {
			$path = __DIR__ . DIRECTORY_SEPARATOR . 'weather.db';
			$dsn = "sqlite:" . $path;
			$pdo = new PDO($dsn, null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
		}
		// create table if not exists
		$pdo->exec("CREATE TABLE IF NOT EXISTS weather_log (
			id INTEGER PRIMARY KEY AUTOINCREMENT,
			town TEXT,
			country TEXT,
			temp REAL,
			feels_like REAL,
			humidity INTEGER,
			wind REAL,
			class TEXT,
			data JSON,
			fetched_at DATETIME DEFAULT CURRENT_TIMESTAMP
		)");
		return $pdo;
	} catch(Exception $e){
		return null;
	}
}

function saveWeatherToDb($pdo, $town, $data, $class){
	if(!$pdo) return;
	try{
		$stmt = $pdo->prepare('INSERT INTO weather_log (town,country,temp,feels_like,humidity,wind,class,data) VALUES (:town,:country,:temp,:feels_like,:humidity,:wind,:class,:data)');
		$stmt->execute([
			':town'=>$town,
			':country'=>$data['sys']['country'] ?? null,
			':temp'=>$data['main']['temp'] ?? null,
			':feels_like'=>$data['main']['feels_like'] ?? null,
			':humidity'=>$data['main']['humidity'] ?? null,
			':wind'=>$data['wind']['speed'] ?? null,
			':class'=>$class,
			':data'=>json_encode($data)
		]);
	} catch(Exception $e){
		// ignore DB write errors for now
	}
}

// Caching configuration
$cacheTtlSeconds = intval(getenv('CACHE_TTL') ?: 600); // default 10 minutes
$cacheDir = __DIR__ . DIRECTORY_SEPARATOR . 'cache';
if(!is_dir($cacheDir)){
	@mkdir($cacheDir, 0755, true);
}

function cachePath($key){
	global $cacheDir;
	return $cacheDir . DIRECTORY_SEPARATOR . $key . '.json';
}

function cacheGet($key, $ttl = 600){
	$path = cachePath($key);
	if(!is_file($path)) return false;
	$contents = @file_get_contents($path);
	if($contents === false) return false;
	$entry = json_decode($contents, true);
	if(!$entry || !isset($entry['fetched_at']) || !isset($entry['data'])) return false;
	if(time() - intval($entry['fetched_at']) > $ttl) return false;
	return $entry;
}

function cacheSet($key, $data){
	$path = cachePath($key);
	$entry = ['fetched_at'=>time(), 'data'=>$data];
	@file_put_contents($path, json_encode($entry));
}

function cacheKeyForCity($city){
	return 'owm_' . md5(mb_strtolower(trim($city)));
}

function fetchWeather($city, $apiKey, $useCache = true){
	global $cacheTtlSeconds;

	$cityEncoded = urlencode($city);
	$url = "https://api.openweathermap.org/data/2.5/weather?q={$cityEncoded}&appid={$apiKey}&units=metric";

	$cacheKey = cacheKeyForCity($city);
	if($useCache){
		$entry = cacheGet($cacheKey, $cacheTtlSeconds);
		if($entry){
			$data = $entry['data'];
			if(is_array($data)){
				$data['__cached'] = true;
				$data['__cached_at'] = $entry['fetched_at'];
			}
			return $data;
		}
	}

	$ch = curl_init($url);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_TIMEOUT, 10);
	$resp = curl_exec($ch);
	$curlErr = curl_error($ch);
	$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
	curl_close($ch);

	if($resp === false || $resp === ''){
		return ['error' => 'Failed to fetch data: '.$curlErr];
	}

	$data = json_decode($resp, true);
	if($data === null){
		return ['error' => 'Invalid JSON response from API'];
	}

	$code = isset($data['cod']) ? intval($data['cod']) : $httpCode;
	if($code === 200){
		cacheSet($cacheKey, $data);
		return $data;
	}

	if(!isset($data['message'])){
		$data['message'] = 'API returned HTTP ' . $httpCode;
	}
	$data['cod'] = $code;
	return $data;
}

function generateRecommendations($w){
	$rec = [];
	$weatherMain = isset($w['weather'][0]['main']) ? $w['weather'][0]['main'] : '';
	$desc = isset($w['weather'][0]['description']) ? $w['weather'][0]['description'] : '';
	$temp = isset($w['main']['temp']) ? $w['main']['temp'] : null;
	$feels = isset($w['main']['feels_like']) ? $w['main']['feels_like'] : null;
	$wind = isset($w['wind']['speed']) ? $w['wind']['speed'] : 0;
	$humidity = isset($w['main']['humidity']) ? $w['main']['humidity'] : null;

	// Weather-condition-based
	if(stripos($weatherMain, 'Rain') !== false || stripos($weatherMain, 'Drizzle') !== false){
		$rec[] = 'Carry an umbrella or wear a waterproof jacket.';
	}
	if(stripos($weatherMain, 'Snow') !== false){
		$rec[] = 'Snow expected — wear warm coat, gloves and boots.';
	}
	if(stripos($weatherMain, 'Thunderstorm') !== false){
		$rec[] = 'Thunderstorms — avoid outdoor activities and seek shelter.';
	}
	if(stripos($weatherMain, 'Clear') !== false){
		$rec[] = 'Clear skies — sunscreen and sunglasses recommended if sunny.';
	}
	if(stripos($weatherMain, 'Clouds') !== false){
		$rec[] = 'Cloudy — pleasant for outdoor walks; light layer recommended.';
	}

	// Temperature-based
	if($temp !== null){
		if($temp <= 0){
			$rec[] = 'Very cold — heavy winter coat, hat, and gloves.';
		} elseif($temp > 0 && $temp <= 8){
			$rec[] = 'Cold — wear a warm jacket and layers.';
		} elseif($temp > 8 && $temp <= 16){
			$rec[] = 'Cool — a light jacket or sweater is suitable.';
		} elseif($temp > 16 && $temp <= 24){
			$rec[] = 'Mild — comfortable with light clothing.';
		} else { // >24
			$rec[] = 'Warm/hot — wear breathable clothing and stay hydrated.';
		}
	}

	// Wind
	if($wind >= 10){
		$rec[] = 'Windy conditions — secure hats/loose items and be cautious near water.';
	}

	// Humidity
	if($humidity !== null && $humidity >= 85){
		$rec[] = 'High humidity — it may feel warmer; stay hydrated.';
	}

	// Feels-like vs actual
	if($feels !== null && abs($feels - $temp) >= 4){
		$rec[] = "Feels like {$feels}°C — dress accordingly to perceived temperature.";
	}

	// If no specific recs, give a generic one
	if(empty($rec)){
		$rec[] = 'No special precautions — enjoy your day.';
	}

	return $rec;
}

// Generate plant recommendations based on class/conditions
function generatePlantRecommendations($class, $weatherMain = '', $temp = null, $humidity = null){
	$plants = [];

	// Prioritize by broad climate class
	if(strtolower($class) === 'hot'){
		$plants = [
			'Sorghum', 'Millet', 'Sesame', 'Pigeon pea', 'Drought-tolerant Acacia/Prosopis', 'Date palm (irrigated)'
		];
	} elseif(strtolower($class) === 'cold'){
		$plants = [
			'Barley', 'Potato', 'Wheat (cold-tolerant)', 'Oats', 'Apple/pear (mid-altitude)'
		];
	} else { // Mild
		$plants = [
			'Teff (highland)', 'Enset (false banana)', 'Arabica coffee (suitable altitude)', 'Maize', 'Cabbage', 'Root vegetables'
		];
	}

	// Adjust by main weather conditions
	if(stripos($weatherMain, 'Rain') !== false || stripos($weatherMain, 'Drizzle') !== false){
		array_unshift($plants, 'Rice (if lowland flooded)');
		$plants[] = 'Taro / Colocasia (wet areas)';
	}
	if(stripos($weatherMain, 'Snow') !== false || stripos($weatherMain, 'Cold') !== false){
		$plants[] = 'Cold-hardy potato and barley varieties';
	}

	// Humidity adjustment
	if($humidity !== null && $humidity > 80){
		$plants[] = 'Banana / plantain (if temperature and altitude suitable)';
	}

	// Ensure uniqueness and return top 6
	$plants = array_values(array_unique($plants));
	return array_slice($plants, 0, 6);
}

// Explicit town -> plant mapping (typical/adaptive crops for Ethiopian towns)
$townPlantMap = [
	'addis ababa' => ['Teff','Barley','Wheat','Potato','Enset','Arabica coffee'],
	'dire dawa' => ['Sorghum','Millet','Sesame','Pigeon pea','Acacia/Prosopis'],
	'mekelle' => ['Teff','Barley','Lentils','Potato','Sorghum'],
	'bahir dar' => ['Teff','Maize','Rice (irrigated)','Banana','Mango'],
	'gondar' => ['Teff','Barley','Wheat','Potato','Apples'],
	'jimma' => ['Arabica coffee','Enset','Banana','Maize','Root crops'],
	'harar' => ['Khat','Sorghum','Sesame','Mango'],
	'hawassa' => ['Maize','Enset','Banana','Avocado','Teff'],
	'dilla' => ['Arabica coffee','Enset','Maize','Banana','Vegetables'],
	'dessie' => ['Teff','Barley','Wheat','Potato','Cabbage'],
	'gemba' => ['Teff/Barley/Potato (highland) or Sorghum/Millet/Sesame (lowland)'],
	'shashamane' => ['Maize','Teff','Coffee','Enset','Vegetables'],
	'asosa' => ['Sorghum','Groundnut','Sesame','Drought-tolerant shrubs'],
	'arba minch' => ['Mango','Banana','Maize','Sorghum','Avocado'],
	'adama' => ['Sorghum','Maize','Sesame','Khat','Fruit trees'],
	'nekemte' => ['Coffee','Maize','Enset','Banana','Teff'],
	'debre markos' => ['Teff','Barley','Wheat','Potato'],
	'sodo' => ['Enset','Maize','Teff','Root crops','Coffee (shaded)'],
	'jijiga' => ['Sorghum','Millet','Drought-tolerant shrubs','Acacia'],
	'bishoftu' => ['Maize','Teff','Vegetables','Mango','Avocado'],
	'dabat' => ['Barley','Teff','Potato','Cabbage']
];

function getMappedPlantsForTown($town){
	global $townPlantMap;
	if(!$town) return null;
	$key = mb_strtolower(trim($town));
	// some towns might be sent with country suffix 'Addis Ababa,ET'
	$key = preg_replace('/,.*$/', '', $key);
	if(isset($townPlantMap[$key])) return $townPlantMap[$key];
	return null;
}

// Plant traits used for simple scoring
$plantTraits = [
	'Teff' => ['temp'=>'mild','water'=>'moderate','altitude'=>'high'],
	'Barley' => ['temp'=>'cold','water'=>'moderate','altitude'=>'high'],
	'Wheat' => ['temp'=>'mild','water'=>'moderate','altitude'=>'high'],
	'Potato' => ['temp'=>'cold','water'=>'moderate','altitude'=>'high'],
	'Enset' => ['temp'=>'mild','water'=>'high','altitude'=>'high'],
	'Arabica coffee' => ['temp'=>'mild','water'=>'high','altitude'=>'mid-high'],
	'Sorghum' => ['temp'=>'hot','water'=>'low','altitude'=>'low'],
	'Millet' => ['temp'=>'hot','water'=>'low','altitude'=>'low'],
	'Sesame' => ['temp'=>'hot','water'=>'low','altitude'=>'low'],
	'Pigeon pea' => ['temp'=>'hot','water'=>'low','altitude'=>'low'],
	'Rice' => ['temp'=>'mild','water'=>'high','altitude'=>'low'],
	'Banana' => ['temp'=>'hot','water'=>'high','altitude'=>'low-mid'],
	'Mango' => ['temp'=>'hot','water'=>'moderate','altitude'=>'low'],
	'Khat' => ['temp'=>'hot','water'=>'low','altitude'=>'low-mid'],
	'Maize' => ['temp'=>'mild','water'=>'moderate','altitude'=>'low-mid'],
	'Avocado' => ['temp'=>'mild','water'=>'moderate','altitude'=>'low-mid'],
	'Coffee' => ['temp'=>'mild','water'=>'high','altitude'=>'mid-high'],
	'Taro' => ['temp'=>'mild','water'=>'high','altitude'=>'low'],
	'Barley' => ['temp'=>'cold','water'=>'moderate','altitude'=>'high'],
];

// Score and select best plant matches for a town given API weather data
function selectBestPlants($town, $data, $max = 4){
	global $plantTraits;
	$candidates = [];

	// start with mapped plants for town (if available)
	$mapped = getMappedPlantsForTown($town);
	if($mapped && is_array($mapped)){
		foreach($mapped as $p){
			$candidates[$p] = ['score'=>3, 'reasons'=>['Town-typical crop']];
		}
	}

	// also add dynamic suggestions from generatePlantRecommendations (broad)
	$class = 'Unknown';
	$temp = $data['main']['temp'] ?? null;
	if($temp !== null){
		if($temp <= 8) $class = 'Cold';
		elseif($temp > 24) $class = 'Hot';
		else $class = 'Mild';
	}
	$dynamic = generatePlantRecommendations($class, $data['weather'][0]['main'] ?? '', $temp, $data['main']['humidity'] ?? null);
	foreach($dynamic as $p){
		if(!isset($candidates[$p])) $candidates[$p] = ['score'=>1, 'reasons'=>['Weather-derived suggestion']];
		else $candidates[$p]['score'] += 1;
	}

	// evaluate traits against current weather
	$weatherMain = strtolower($data['weather'][0]['main'] ?? '');
	$humidity = $data['main']['humidity'] ?? null;

	foreach($candidates as $pname => &$meta){
		$base = $meta['score'];
		$added = 0;
		$reasonList = $meta['reasons'];
		// trait match increases score
		$key = preg_replace('/\s*\(.*$/','',$pname); // strip parenthesis notes
		$keyLower = $key;
		// try direct trait lookup (case-insensitive)
		foreach($plantTraits as $k => $traits){
			if(stripos($k, $keyLower) !== false || stripos($keyLower, $k) !== false){
				// temperature preference
				if(isset($traits['temp']) && $class !== 'Unknown'){
					if(($traits['temp'] === 'hot' && $class === 'Hot') || ($traits['temp'] === 'cold' && $class === 'Cold') || ($traits['temp'] === 'mild' && $class === 'Mild')){
						$added += 2;
						$reasonList[] = 'Temperature matches plant preference';
					}
				}
				// humidity
				if(isset($traits['water']) && $humidity !== null){
					if($traits['water'] === 'high' && $humidity > 70){ $added += 1; $reasonList[] = 'High humidity suits this plant'; }
					if($traits['water'] === 'low' && $humidity < 50){ $added += 1; $reasonList[] = 'Low humidity suits this plant'; }
				}
				break;
			}
		}
		// weather-main overrides
		if(stripos($weatherMain,'rain') !== false && stripos($pname,'Rice') !== false){ $added += 2; $reasonList[] = 'Rain supports paddy/rice'; }

		$meta['score'] = $base + $added;
		$meta['reasons'] = $reasonList;
	}
	unset($meta);

	// sort candidates by score desc
	uasort($candidates, function($a,$b){ return $b['score'] - $a['score']; });

	$result = [];
	foreach($candidates as $pname => $meta){
		$result[] = ['plant'=>$pname, 'score'=>$meta['score'], 'reasons'=>$meta['reasons']];
		if(count($result) >= $max) break;
	}
	return $result;
}

$overview = isset($_GET['overview']) ? $_GET['overview'] : '';
$city = isset($_GET['city']) && trim($_GET['city']) !== '' ? trim($_GET['city']) :'gemba';
$result = null;

// Predefined Ethiopian towns for overview
$ethiopianTowns = [
	'GOJJAM','Addis Ababa','Dire Dawa','Mekelle','Bahir Dar','Gondar','Jimma','Harar','Hawassa','Dilla','Dessie','gemba','Shashamane','Asosa','Arba Minch','Adama','Nekemte','Debre Markos','Sodo','Jijiga','Bishoftu','Dabat'
    
];

// initialize DB (optional)
$pdo = initDb();
$overviewResults = [];
if($overview === 'ethiopia'){
	if($apiKeyMissing){
		// avoid making multiple failing API calls
		$overviewError = $apiKeyMessage;
	} else {
		$overviewError = null;
		foreach($ethiopianTowns as $town){
			$data = fetchWeather($town . ',ET', $apiKey);
			// detect invalid-key early and stop further calls
			if(isset($data['cod']) && intval($data['cod']) === 401){
				$overviewError = isset($data['message']) ? $data['message'] : 'Invalid API key (401)';
				break;
			}
			if(isset($data['error'])){
				$overviewResults[$town] = ['error' => $data['error']];
				continue;
			}
			$rec = generateRecommendations($data);
			$temp = isset($data['main']['temp']) ? $data['main']['temp'] : null;
			$class = 'Unknown';
			if($temp !== null){
				if($temp <= 8) $class = 'Cold';
				elseif($temp > 24) $class = 'Hot';
				else $class = 'Mild';
			}
			$plants = generatePlantRecommendations($class, $data['weather'][0]['main'] ?? '', $temp, $data['main']['humidity'] ?? null);
			$best = selectBestPlants($town, $data, 4);
			$overviewResults[$town] = ['data' => $data, 'recs' => $rec, 'class' => $class, 'plants' => $plants, 'best' => $best];
			// persist
			saveWeatherToDb($pdo, $town, $data, $class);
			// small delay to be polite to API (optional)
			usleep(150000);
		}
	}
} else {
	if($apiKeyMissing){
		$result = ['error' => $apiKeyMessage];
	} else {
		$result = fetchWeather($city, $apiKey);
		if(isset($result['cod']) && intval($result['cod']) === 401){
			// instruct user about API key
			$result = ['error' => isset($result['message']) ? $result['message'] . '. Set your API key in OPENWEATHER_API_KEY or replace placeholder.' : 'Invalid API key.'];
		} else {
			if(!isset($result['error'])){
				$temp = isset($result['main']['temp']) ? $result['main']['temp'] : null;
				$class = 'Unknown';
				if($temp !== null){
					if($temp <= 8) $class = 'Cold';
					elseif($temp > 24) $class = 'Hot';
					else $class = 'Mild';
				}
				saveWeatherToDb($pdo, $city, $result, $class);
			}
		}
	}
}

?>
<!doctype html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width,initial-scale=1">
	<title>Weather Detector & Recommendations</title>
	<link rel="stylesheet" href="styles.css">
	<style>
		/* fallback minimal styles */
		body{font-family:Arial,Helvetica,sans-serif;max-width:720px;margin:20px auto;padding:0 12px}
	</style>
</head>
<body>
	<h1>Weather Detector & Recommendations</h1>
	<form method="get">
		<input type="text" name="city" placeholder="City name (e.g. London, Paris)" value="<?php echo htmlspecialchars($city); ?>">
		<input type="submit" value="Check">
		<button type="submit" name="overview" value="ethiopia">Ethiopia overview</button>
	</form>

	<?php if($overview === 'ethiopia'): ?>
		<h2>Ethiopia towns overview</h2>
		<?php foreach($overviewResults as $town => $item): ?>
			<div class="card">
				<h3><?php echo htmlspecialchars($town); ?> — <?php echo htmlspecialchars($item['class'] ?? ''); ?></h3>
				<?php if(isset($item['error'])): ?>
					<p><strong>Error:</strong> <?php echo htmlspecialchars($item['error']); ?></p>
				<?php else:
					$d = $item['data'];
				?>
					<?php $mapped = getMappedPlantsForTown($town); if($mapped): ?>
						<h4>Recommended plants (typical for <?php echo htmlspecialchars($town); ?>)</h4>
						<?php foreach($mapped as $mp): ?>
							<div class="rec small"><?php echo htmlspecialchars($mp); ?></div>
						<?php endforeach; ?>
					<?php endif; ?>
				<?php if(isset($d['__cached']) && isset($d['__cached_at'])): ?>
					<p class="small">Cached <?php echo intval((time() - intval($d['__cached_at']))/60); ?> minute(s) ago</p>
				<?php endif; ?>
					<p><?php echo htmlspecialchars(ucfirst($d['weather'][0]['description'])); ?> — Temp: <?php echo intval($d['main']['temp']); ?>°C (feels like <?php echo intval($d['main']['feels_like']); ?>°C)</p>
					<p>Humidity: <?php echo intval($d['main']['humidity']); ?>% &nbsp;|&nbsp; Wind: <?php echo htmlspecialchars($d['wind']['speed']); ?> m/s</p>
					<h4>Recommendations</h4>
					<?php foreach($item['recs'] as $r): ?>
						<div class="rec"><?php echo htmlspecialchars($r); ?></div>
					<?php endforeach; ?>
					<?php if(!empty($item['best'])): ?>
						<h4>Best plant matches</h4>
						<?php foreach($item['best'] as $b): ?>
							<div class="rec small"><strong><?php echo htmlspecialchars($b['plant']); ?></strong>
								<div class="small">Score: <?php echo intval($b['score']); ?> — <?php echo htmlspecialchars(implode('; ',$b['reasons'])); ?></div>
							</div>
						<?php endforeach; ?>
					<?php endif; ?>
				<?php endif; ?>
			</div>
		<?php endforeach; ?>
	<?php else: ?>
		<?php if(isset($result['error'])): ?>
			<div class="card">
				<strong>Error:</strong> <?php echo htmlspecialchars($result['error']); ?>
				<p>Check your API key and network connection.</p>
			</div>
		<?php else:
			$rec = generateRecommendations($result);
			$w = $result;
			$icon = isset($w['weather'][0]['icon']) ? $w['weather'][0]['icon'] : '';
			$tempNow = isset($w['main']['temp']) ? $w['main']['temp'] : null;
			$classNow = 'Unknown';
			if($tempNow !== null){
				if($tempNow <= 8) $classNow = 'Cold';
				elseif($tempNow > 24) $classNow = 'Hot';
				else $classNow = 'Mild';
			}
			$plants = generatePlantRecommendations($classNow, $w['weather'][0]['main'] ?? '', $tempNow, $w['main']['humidity'] ?? null);
			$bestSingle = selectBestPlants($w['name'] ?? $city, $w, 4);
		?>
		<div class="card">
				<?php if(isset($w['__cached']) && isset($w['__cached_at'])): ?>
					<p class="small">Cached <?php echo intval((time() - intval($w['__cached_at']))/60); ?> minute(s) ago</p>
				<?php endif; ?>
				<?php $mappedSingle = getMappedPlantsForTown($w['name'] ?? $city); if($mappedSingle): ?>
					<h3>Recommended plants (typical for <?php echo htmlspecialchars($w['name'] ?? $city); ?>)</h3>
					<?php foreach($mappedSingle as $mp): ?>
						<div class="rec small"><?php echo htmlspecialchars($mp); ?></div>
					<?php endforeach; ?>
				<?php endif; ?>
				<h2><?php echo htmlspecialchars($w['name'].', '.($w['sys']['country'] ?? '')); ?></h2>
			<p>
				<strong><?php echo htmlspecialchars(ucfirst($w['weather'][0]['description'])); ?></strong>
				<?php if($icon): ?>
					<img src="https://openweathermap.org/img/wn/<?php echo $icon; ?>@2x.png" alt="icon" style="vertical-align:middle">
				<?php endif; ?>
			</p>
			<p>Temperature: <?php echo intval($w['main']['temp']); ?>°C — feels like <?php echo intval($w['main']['feels_like']); ?>°C</p>
			<p>Humidity: <?php echo intval($w['main']['humidity']); ?>% &nbsp;|&nbsp; Wind: <?php echo htmlspecialchars($w['wind']['speed']); ?> m/s</p>
			<h3>Recommendations</h3>
			<?php foreach($rec as $r): ?>
				<div class="rec"><?php echo htmlspecialchars($r); ?></div>
			<?php endforeach; ?>
			<h3>Plant suggestions</h3>
			<?php foreach($plants as $p): ?>
				<div class="rec small"><?php echo htmlspecialchars($p); ?></div>
			<?php endforeach; ?>
			<?php if(!empty($bestSingle)): ?>
				<h3>Best plant matches</h3>
				<?php foreach($bestSingle as $b): ?>
					<div class="rec small"><strong><?php echo htmlspecialchars($b['plant']); ?></strong>
						<div class="small">Score: <?php echo intval($b['score']); ?> — <?php echo htmlspecialchars(implode('; ',$b['reasons'])); ?></div>
					</div>
				<?php endforeach; ?>
			<?php endif; ?>
		</div>
		<?php endif; ?>
	<?php endif; ?>

	<div style="margin-top:18px;color:#555;font-size:90%">
		<strong>Notes:</strong>
		<ul>
			<li>Set your OpenWeatherMap API key by replacing <em>PUT_YOUR_API_KEY_HERE</em> or setting environment variable <strong>OPENWEATHER_API_KEY</strong>.</li>
			<li>Open this page in your browser: <strong>http://localhost/weather/wheather.php</strong></li>
			<li>This uses the OpenWeatherMap current weather API; consider adding error handling and caching for production.</li>
		</ul>
	</div>
</body>
</html>

<?php
// end
?>
