<?php

ini_set('max_execution_time', '6000');

error_reporting(-1);
ini_set('display_errors', 'On');


require_once dirname(__FILE__).'/../api/UPcms.php';

class ImportAjax extends UPcms
{
    private $url = 'https://instrument.ru/api/personalFeed/1645a8d67f3463cf77bb0b2ee5311d8d/';
    
    private $added_categories = array();
    
    private $counter = array(
        'product_added' => 0,
        'product_updated' => 0,
        'category_added' => 0,
        'category_updated' => 0,
    );

    // Будем запоминать, какие картинки записали
    // private $imagesLoaded = array();

    //private $vendorNames = array('ЗУБР');
    
    protected $tempdir;
    protected $logdir = 'logs/';
    protected $imageLogFileName = 'imageLogFile.txt';
    
    const LOGGING = 1;
    // Удалять все товары
    const TRUNCATE_TABLE = 0;
    // Удалять категории
    const TRUNCATE_CATEGORIES = 0;
    // ОбНУлять наличие
    const STOCK_NULLING = 0;
    // Импортировать категории
    const IMPORT_CATEGORIES = 0;
    // Скачивать каталог актуальный
    const DOWNLOAD_FILE = 1;
    // Обновлять товары
    const UPDATE_PRODUCTS = 0;

    // Не будет скачивать новый файл
    // Будет использовать  importTest.xml
    const DEBUG = 0;
    
    public function __construct()
    {
        parent::__construct();

        $this->tempdir = $this->config->root_dir.'cron/';
        $this->logdir = $this->config->root_dir.'cron/'.$this->logdir;
        
        if (self::TRUNCATE_TABLE) {
            $this->truncate();
        }
    }
    
    
    public function run()
    {
        if (self::STOCK_NULLING) {
            $query = $this->db->placehold("
                UPDATE __variants
                SET stock = 0
            ");
            $this->db->query($query);
        }
        
        $this->log(PHP_EOL.date('d-m-Y H:i:s'));
        $this->log('START '.__CLASS__);
        
        

        if (self::DEBUG) {
            $temp_filename = $this->tempdir.'importTest.xml';
            $uploaded = true;
            $this->log('DEBUG upload');
        } else {
            $temp_filename = $this->tempdir.'import.xml';
            if (self::DOWNLOAD_FILE) {
                $uploaded = $this->load_file($this->url, $temp_filename);
            } else {
                $uploaded = true;
            }
            $this->log('upload');
        }

        
        
        if ($uploaded) {

            // Создаем/открываем файл с картинками в режиме записи
            $imageLogFile = fopen($this->tempdir.$this->imageLogFileName, 'w');
            fclose($imageLogFile);

            $doc = new DOMDocument;

            // if (!self::DEBUG) {
            //     $this->removeImages();
            // }

            $reader = new XMLReader();
            $reader->open($temp_filename);
            

            while ($reader->read()) {

                if ($reader->name == 'category' && XMLReader::ELEMENT == $reader->nodeType) {
                    $node = simplexml_import_dom($doc->importNode($reader->expand(), true));
                    
                    if (self::IMPORT_CATEGORIES) {
                        $parent_id = (string)$node['parentId'];
                        $item = array(
                            'id' => (string)$node['id'],
                            'name' => (string)$node,
                            'parent_id' => empty($parent_id) ? 0 : (int)$parent_id
                        );
                        //echo '<hr />'.__FILE__.':'.__LINE__.'<pre>'; var_dump($item); echo '</pre>';
                   
                        $this->import_category($item);
                    }
                }

                if ($reader->name == 'offer' && XMLReader::ELEMENT == $reader->nodeType) {

                    $node = simplexml_import_dom($doc->importNode($reader->expand(), true));
                    //if(in_array((string)$node->vendor, $this->vendorNames))
                    //{
                    // Получим свойства
                    $features = array();
                    $dom_element = dom_import_simplexml($node);
                    foreach ($dom_element->childNodes as $dom_child) {
                        switch ($dom_child->nodeType) {
                            case XML_ELEMENT_NODE:
                                $tmpName = simplexml_import_dom($dom_child);
                                $tmpName = (string) $tmpName['name'];
                                    
                                if(!empty($tmpName)) {
                                    $features[$tmpName] = $dom_child->nodeValue;
                                }
                               
                                break;
                        }
                    }

                    // Проверяем наличие
                    $available = (string)$node->available;

                    $stock = 0;
                    // if($available == "Под заказ")
                    //     $stock = 0;
                    if($available == "В наличии") {
                        $stock = null;
                    }

                    $item = array(
                        'id' => (string)$node['id'],
                        'url' => (string)$node->url,
                        'price' => (string)$node->price,
                        'description' => (string)$node->description,
                        'image' => $node->picture,
                        'name' => (string)$node->name,
                        'category_id' => (string)$node->categoryId,
                        'sku' => (string)$node->sku,
                        'brand' => (string)$node->vendor,
                        'stock' => $stock,
                        'features' => $features
                    );

                    // echo '<hr />'.__FILE__.':'.__LINE__.'<pre>'; var_dump($item); echo '</pre>';

                    $this->import_item($item);
                    //}
                }
            }

            
        
            $reader->close();
            unset($reader);

            
            // if ($imageLogFile && $this->imagesLoaded) {
            //     fwrite($imageLogFile, implode(PHP_EOL, $this->imagesLoaded));
            // }
           
        
            $this->report();
        }
    
    }

    private function removeImages()
    {
        $this->db->query("TRUNCATE TABLE __images");

        // Перенесено в крон
        // $rm_files = glob($this->config->root_dir.$this->config->resized_images_dir.'*'); // получаем список файлов
        // foreach($rm_files as $file){ // проходим по списку
        //     if(is_file($file)) // если это файл, то удаляем его
        //         @unlink($file);
        // }

        // $rm_files = glob($this->config->root_dir.$this->config->original_images_dir.'*'); // получаем список файлов
        // foreach($rm_files as $file){ // проходим по списку
        //     if(is_file($file)) // если это файл, то удаляем его
        //         @unlink($file);
        // }
    }
        
    private function truncate()
    {
        if (self::TRUNCATE_CATEGORIES) {
            $this->db->query("TRUNCATE TABLE __categories");
        }
        $this->db->query("TRUNCATE TABLE __products");
        $this->db->query("TRUNCATE TABLE __variants");
        $this->db->query("TRUNCATE TABLE __products_categories");
        $this->db->query("TRUNCATE TABLE __images");
    }
    
    private function import_category($item)
    {
        $item_id = (int)$item['id'];
        if (!empty($item_id)) {
            $query = $this->db->placehold("
                SELECT id 
                FROM __categories 
                WHERE external_id = ?
            ", $item['id']);
            $this->db->query($query);

            if (!($category_id = $this->db->result('id'))) {
                $add_category = array(
                    'name' => $item['name'],
                    'meta_title' => $item['name'],
                    'url' => $this->translit($item['name']),
                    'external_id' => $item['id'],
                    'visible' => 1,
                );
                $category_id = $this->categories->add_category($add_category);
                
                $this->counter['category_added']++;
            } else {
                $this->counter['category_updated']++;
            }

            // родительская категория
            $this->db->query("
                SELECT id 
                FROM __categories 
                WHERE external_id = ?
            ", $item['parent_id']);
            $parent_id = $this->db->result('id');
            $this->categories->update_category($category_id, array(
                'parent_id' => $parent_id
            ));
            
            $this->added_categories[$item['id']] = $category_id;
        }
    }

    // Импорт одного товара $item[column_name] = value;
    private function import_item($item)
    {
        if (empty($item['id'])) {
            return;
        }

        //echo __FILE__.' '.__LINE__.'<br /><pre>';var_dump($item);echo '</pre><hr />';

        // ищем или создаем продукт
        $query = $this->db->placehold("
            SELECT id 
            FROM __products 
            WHERE external_id = ?
        ", $item['id']);
        $this->db->query($query);

        $add_product = array(
            'name' => $item['name'],
            'meta_title' => $item['name'],
            'url' => $this->translit($item['name']),
            'body' => $item['description'],
            'visible' => 1,
            'external_id' => $item['id'],
        );
        
        if (!($product_id = $this->db->result('id'))) {
            $product_id = $this->products->add_product($add_product);
            $this->counter['product_added']++;
        } else {
            if(self::UPDATE_PRODUCTS) {
                $this->products->update_product($add_product);
            }
            $this->counter['product_updated']++;
        }

        $brand_name = $item['brand'];
        // Добавим бренд
        // Найдем его по имени
        $this->db->query('SELECT id FROM __brands WHERE name=?', $brand_name);
        if(!$brand_id = $this->db->result('id')) {
            // Создадим, если не найден
            $brand_id = $this->brands->add_brand(array('name'=>$brand_name, 'meta_title'=>$brand_name, 'meta_keywords'=>$brand_name, 'meta_description'=>$brand_name, 'url'=>$this->translit($brand_name)));
        }
        if(!empty($brand_id)) {
            $this->products->update_product($product_id, array('brand_id'=>$brand_id));
        }
        
        // ишем или создаем вариант
        $query = $this->db->placehold("
            SELECT id 
            FROM __variants 
            WHERE product_id = ?
        ", $product_id);
        $this->db->query($query);
        
        if ($variant_id = $this->db->result('id')) {
            $this->variants->update_variant($variant_id, array(
                'price' => $item['price'],
                //'stock' => empty($item['stock']) ? 0 : NULL
                'stock' => $item['stock']
            ));
        } else {
            $variant_id = $this->variants->add_variant(array(
                'sku' => $item['sku'],
                'price' => $item['price'],
                'product_id' => $product_id,
                'stock' => $item['stock'],
            ));
        }
        
        // категории
        $query = $this->db->placehold("
            SELECT id 
            FROM __categories 
            WHERE external_id = ?
        ", $item['category_id']);
        $this->db->query($query);
        
        if ($category_id = $this->db->result('id')) {
            $this->categories->add_product_category($product_id, $category_id);
        }
        
        // изображения
        if (!empty($item['image'])) {

            // Удаляем изображения старые
            // $old_images = $this->get_images(array('product_id'=>$id));
            // if($old_images)
            // {
            //     foreach($old_images as $i)
            //         $this->products->delete_image($i->id);
            // }

            if($item['image']->count() > 1) {
                foreach($item['image'] as $img) {
                    $this->importImage((string)$img, $product_id);
                }
            } else {
                $this->importImage((string)$item['image'], $product_id);
            }
        
            //echo __FILE__.' '.__LINE__.'<br /><pre>';var_dump($pathinfo);echo '</pre><hr />';
        }

        // Фичи
        if (!empty($item['features'])) {
            foreach($item['features'] as $feature => $option) {
                $this->importFeature($feature, $option, $product_id, $category_id);
            }
        }

    

    }

    protected function importImage($image, $product_id)
    {

        //$image = str_replace('http', 'https', $image);
        $pathinfo = pathinfo($image);
        // $expl = explode('/', $pathinfo['dirname']);
        // $last_elem = array_shift($expl);
        $filename = $pathinfo['basename'];
        
        $local_filename = $this->config->root_dir.$this->config->original_images_dir.$filename;

        if (!file_exists($local_filename)) {
            $this->load_file($image, $local_filename);
        }
        
        if (file_exists($local_filename) && getimagesize($local_filename)) {
            $query = $this->db->placehold("
                SELECT id 
                FROM __images 
                WHERE product_id = ?
                AND filename = ?
            ", $product_id, $filename);
            $this->db->query($query);
            
            if (!($image_id = $this->db->result('id'))) {
                $this->products->add_image($product_id, $filename);
            }

            //$this->imagesLoaded[] = $filename;

            // Открываем файл в режиме добавления и записываем информацию о файле
            $fileHandle = fopen($this->tempdir.$this->imageLogFileName, 'a');
            fwrite($fileHandle, $filename . PHP_EOL); // Добавляем PHP_EOL для перехода на новую строку
            fclose($fileHandle);
        }
    }

    protected function importFeature($feature, $option, $product_id, $category_id)
    {

        // Сначала проверим есть ли вообще такая фича
        $query = $this->db->placehold("
            SELECT id 
            FROM __features 
            WHERE name = ?
        ", $feature);

        $this->db->query($query);
        
        if (!($feature_id = $this->db->result('id'))) {
            $feature_id = $this->features->add_feature(array('name'=>$feature));
        }
        
        // Добавим фичу в категорию, чтобы выводило в товаре
        $this->features->add_feature_category($feature_id, $category_id);

        // Теперь уже можно и опцию
        $this->features->update_option($product_id, $feature_id, $option);
    }

    protected function log($message)
    {
        if (self::LOGGING) {
            $maxlogsize = 1024*1024;
            $logfile = $this->logdir.'log3.htm';
            
            if (filesize($logfile) > $maxlogsize) {
                $new_logfile = $this->logdir.date('YmdHis').'.htm';
                rename($logfile, $new_logfile);
                file_put_contents($logfile, '');
            }
            file_put_contents($logfile, $message.PHP_EOL, FILE_APPEND);
        }
    }
    
    protected function get_file($url)
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        
        $data = curl_exec($ch);

        curl_close($ch);

        return $data;
    }
    
    protected function load_file($url, $filename)
    {
        $curl = curl_init();
        $file = fopen($filename, 'w');
        curl_setopt($curl, CURLOPT_URL, $url); #input
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_FILE, $file); #output
        $result = curl_exec($curl);
        curl_close($curl);
        fclose($file);
        
        return $result;
    }
    
    protected function translit($text)
    {
        $ru = explode('-', "А-а-Б-б-В-в-Ґ-ґ-Г-г-Д-д-Е-е-Ё-ё-Є-є-Ж-ж-З-з-И-и-І-і-Ї-ї-Й-й-К-к-Л-л-М-м-Н-н-О-о-П-п-Р-р-С-с-Т-т-У-у-Ф-ф-Х-х-Ц-ц-Ч-ч-Ш-ш-Щ-щ-Ъ-ъ-Ы-ы-Ь-ь-Э-э-Ю-ю-Я-я");
        $en = explode('-', "A-a-B-b-V-v-G-g-G-g-D-d-E-e-E-e-E-e-ZH-zh-Z-z-I-i-I-i-I-i-J-j-K-k-L-l-M-m-N-n-O-o-P-p-R-r-S-s-T-t-U-u-F-f-H-h-TS-ts-CH-ch-SH-sh-SCH-sch---Y-y---E-e-YU-yu-YA-ya");

        $res = str_replace($ru, $en, $text);
        $res = preg_replace("/[\s]+/ui", '-', $res);
        $res = preg_replace('/[^\p{L}\p{Nd}\d-]/ui', '', $res);
        $res = strtolower($res);
        return $res;
    }

    private function report()
    {
        $this->log('Категорий добавлено: '.$this->counter['category_added']);
        $this->log('Категорий обновлено: '.$this->counter['category_updated']);
        $this->log('Продуктов добавлено: '.$this->counter['product_added']);
        $this->log('Продуктов обновлено: '.$this->counter['product_updated']);
        
        echo '<html>';
        echo '<head>';
        echo '<title>Импорт</title>';
        echo '</head>';
        echo '<body>';
        echo '<h2>Импорт завершен</h2>';
        echo '<ul>';
        echo '<li style="color:green">Категорий добавлено: '.$this->counter['category_added'].'</li>';
        echo '<li style="color:blue">Категорий обновлено: '.$this->counter['category_updated'].'</li>';
        echo '<li style="color:green">Продуктов добавлено: '.$this->counter['product_added'].'</li>';
        echo '<li style="color:blue">Продуктов обновлено: '.$this->counter['product_updated'].'</li>';
        echo '<li style="color:blue">Картинок обновлено: '.count($this->imagesLoaded).'</li>';
        echo '</ul>';
        echo '</body>';
        echo '</html>';
        
    }

}

$ajax = new ImportAjax();
$ajax->run();
