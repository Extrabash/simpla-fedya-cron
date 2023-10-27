<?php

require_once('../../api/UPcms.php');
$upcms = new UPcms();

$avitoAddress = 'Москва, улица Лесная, 9';
$avitoPhone = '+7 495 777-10-66';
$avitoCondition = 'Новое';
$avitoAdType = 'Товар приобретен на продажу'; //'Товар от производителя'
$avitoAdManager = 'Иван Петров-Водкин';
$avitoAdStatus = 'Free';
$avitoAllowEmail = 'Да';

// Валюты
$currencies = $upcms->money->get_currencies(array('enabled'=>1));
$main_currency = reset($currencies);


// Категории

$upcms->db->query("SELECT 
c.id,
c.avitoGood, 
c.avitoPercentage
FROM __categories c
WHERE c.avitoGood IS NOT NULL AND c.avitoGood <> ''");
// $upcms->db->query("SELECT 
// c.id,
// c.avitoGood, 
// c.avitoPercentage
// FROM __categories c
// WHERE 1");

$categories = $upcms->db->results();
if($categories)
{
	$reCats = array();
	foreach($categories as &$c)
	{
		$c->avitoPercentage = $c->avitoPercentage / 100;
		// Разобьем категории по шаблону
		$reCats1 = explode('|', $c->avitoGood);
		foreach($reCats1 as &$pC)
		{
			$pC = explode(':', $pC);
		}
		$c->avitoCats = $reCats1;
		$reCats[$c->id] = $c;
	}
	// print_r('<pre>');
	// print_r($reCats);
	// print_r('</pre>');
	$catsKeys = array_keys($reCats);

	// Запросим товары
	$upcms->db->query("SET SQL_BIG_SELECTS=1");
	$productsQuery = $upcms->db->placehold('SELECT
			p.name as product_name,
			p.id as product_id,
			p.url,
			p.annotation,
			p.body,
			pc.category_id
		FROM
			__products p
			LEFT JOIN __products_categories pc ON p.id = pc.product_id
			AND pc.position =(
				SELECT
					MIN(position)
				FROM
					__products_categories
				WHERE
					product_id = p.id
				LIMIT
					1
			)
			LEFT JOIN __categories c ON pc.category_id = c.id
		WHERE
			p.visible
			AND (pc.category_id in (?@))
		ORDER BY
			p.id', $catsKeys);

	$upcms->db->query($productsQuery);

	$products = $upcms->db->results();
}


if($products)
{
	$newProds = array();
	foreach($products as &$prdct)
		$newProds[$prdct->product_id] = $prdct;
	
	$productsIds = array_keys($newProds);

	// Получим варианты
	$variantsQuery = $upcms->db->placehold(' SELECT
											v.price,
											v.id as variant_id,
											v.name as variant_name,
											v.sku,
											v.position as variant_position,
											v.product_id
										FROM
											__variants v
										WHERE
											(
												v.stock > 0
												OR v.stock is NULL
											)
											AND (v.product_id in (?@))
										', $productsIds);
	$upcms->db->query($variantsQuery);
	$variants = $upcms->db->results();

	// Получим изображения
	$imagesQuery = $upcms->db->placehold('SELECT 
										*
										FROM __images i
										WHERE i.product_id in(?@)
										ORDER BY i.position', $productsIds);
	$upcms->db->query($imagesQuery);
	$images = $upcms->db->results();
	if($images)
		foreach($images as &$image)
			$newProds[$image->product_id]->images[$image->position] = $image;
}
//print_r(count($newProds));


if($variants)
{
	$file = 'avito.xml';
	$firstString = '<Ads formatVersion="3" target="Avito.ru">';
	file_put_contents($file, $firstString);
	
	
	foreach($variants as $variant)
	{
		$p = $newProds[$variant->product_id];
		$cats = $reCats[$p->category_id]->avitoCats;
		$percent = $reCats[$p->category_id]->avitoPercentage;
		$price = round($upcms->money->convert($variant->price*$percent, $main_currency->id, false),2);

		$toPrint = '';
		//<Title>'.htmlspecialchars($p->product_name).($variant->sku?' '.htmlspecialchars($variant->sku):'').'</Title>;
		$toPrint .= '
		<Ad>
			<Id>'.$variant->sku.'</Id>
			<Address>'.$avitoAddress.'</Address>
			<Title>'.htmlspecialchars($p->product_name).'</Title>
			<Description><![CDATA['.$p->body.']]></Description>
			<Price>'.$price.'</Price>';

		// if($brand)
		// {
		// 	$toPrint .= '<Brand>'..'</Brand>
		// 	';
		// }

		if($p->images)
		{
			$toPrint .= '<Images>';
			foreach($p->images as $image)
			{
				
				$toPrint .= '
				<Image url="'.$upcms->design->resize_modifier($image->filename, 800, 800).'" />
			';
			}
			$toPrint .= '</Images>';
		}

		
		foreach($cats as $cat)
		{
			if($cat[1])
				$toPrint .= '
			<'.$cat[1].'>'.$cat[0].'</'.$cat[1].'>';
		}

		$toPrint .= 
		   '<AdStatus>'.$avitoAdStatus.'</AdStatus>
			<AllowEmail>'.$avitoAllowEmail.'</AllowEmail>
			<ManagerName>'.$avitoAdManager.'</ManagerName>
			<Address>'.$avitoAddress.'</Address>
			<ContactPhone>'.$avitoPhone.'</ContactPhone>
			<Condition>'.$avitoCondition.'</Condition>
			<AdType>'.$avitoAdType.'</AdType>
		</Ad>';

		file_put_contents($file, $toPrint, FILE_APPEND);

		unset($p);
	}

	$lastString = '
	</Ads>';
	file_put_contents($file, $lastString, FILE_APPEND);

	print('Успешная выгрузка <a href="https://denzel-market.ru/cron/avito/avito.xml" target="_blank">Скачать файл</a> - адрес https://denzel-market.ru/cron/avito/avito.xml');
}

