<?php

require($_SERVER["DOCUMENT_ROOT"] . "/bitrix/header.php");

/** @global $APPLICATION   */

$APPLICATION->SetTitle('Врачи');
$APPLICATION->SetAdditionalCSS('/local/templates/style.css');

use Models\DoctorPropertyValuesTable as DoctorsTable;
use Models\ProcsPropertyValuesTable as ProcsTable;


//инициализируем переменные

$doctors = [];
$doctor = [];
$procs = [];

$path = trim($_GET['path'],'/');
$action = '';
$doctorName = '';

// разбираем роутинг

if(!empty($path)){
    $path_parts = explode('/',$path);
    if(sizeof($path_parts) < 3){
        if(sizeof($path_parts) == 2 && $path_parts[0] == 'edit') {
            $action = 'edit';
            $doctorName = $path_parts[1];
        } else if(sizeof($path_parts) == 1 && in_array($path_parts[0], ['new','newproc'])) {
            $action = $path_parts[0];
        } else $doctorName = $path_parts[0];
    }
}

//выводим выбранного доктора и отображаем его реквизиты и связаные процедуры

if(!empty($doctorName)){
    $doctor = DoctorsTable::query()
        ->setSelect([
            '*',
            'NAME' => 'ELEMENT.NAME',
            'PROC_IDS_MULTI',
            'ID' => 'ELEMENT.ID'
        ])
        ->where('NAME', $doctorName)
        ->fetch();
    if(is_array($doctor)){
        if($doctor['PROC_IDS_MULTI']){
            $procs = ProcsTable::query()
                ->setSelect(['ELEMENT_NAME' => 'ELEMENT.NAME'])
                ->where("ELEMENT.ID", "in", $doctor['PROC_IDS_MULTI'])
                ->fetchAll();
        }
    }
}



// выводим список всех докторов

if(empty($doctorName) && empty($action)) {
    $doctors = DoctorsTable::query()
        ->setSelect(['*', 'NAME' => 'ELEMENT.NAME', 'ID' => 'ELEMENT.ID'])
        ->fetchAll();
}

// обработка добавления новой процедуры по кнопке Добавить процедуру

if($action == 'newproc'){
    if(isset($_POST['proc-submit'])) {
        unset($_POST['proc-submit']);
        if(ProcsTable::add($_POST)) {
            ?>
            <script>window.location.href = "/local/homeworks/homework3/Doctor";</script>
            <?php
            exit();
        } else echo "Не удалось отредактировать";
    }
}

// обработка добавления(new) или изменения врача(edit)

if($action == 'new' || $action == 'edit'){
    if(isset($_POST['doctor-submit'])) {
       unset($_POST['doctor-submit']);
       if($action == 'edit' && !empty($_POST['ID'])) {
           $ID = $_POST['ID'];
           unset($_POST['ID']);
           $_POST['IBLOCK_ELEMENT_ID'] = $ID;

           $procs = $_POST['PROC_IDS_MULTI'];
           unset($_POST['PROC_IDS_MULTI']);
           CIBlockElement::SetPropertyValues($ID, DoctorsTable::IBLOCK_ID, $procs, "PROC_IDS_MULTI");

           if(DoctorsTable::update($_POST['ID'], $_POST)) {
               ?>
               <script>window.location.href = "/local/homeworks/homework3/Doctor";</script>
               <?php
               exit();
           } else echo "Не удалось обновить";
       }

       if($action == 'new' && DoctorsTable::add($_POST)) {
           ?>
           <script>window.location.href = "/local/homeworks/homework3/Doctor";</script>
           <?php
           exit();
       } else echo "Не удалось добавить";

    }

    $proc_options = ProcsTable::query()->setSelect([
       "ID" => "ELEMENT.ID",
       "ELEMENT_NAME" => "ELEMENT.NAME"
       ])->fetchAll();
    if(!empty($doctorName)) {
           $data = $doctor;
       }
    }
?>

<section class="doctors">
    <h1><a href="/local/homeworks/homework3/Doctor">Врачи</a></h1>

    <?php if(empty($action)):?>
    <div class="add-buttons">
        <?php if(empty($doctorName)):?>
        <a href="/local/homeworks/homework3/Doctor/new"><button>Добавить врача</button></a>
        <a href="/local/homeworks/homework3/Doctor/newproc"><button>Добавить процедуру</button></a>
        <?php else:?>
        <a href="/local/homeworks/homework3/Doctor/edit/<?=$doctorName?>"><button>Изменить данные врача</button></a>
        <?php endif;?>
    </div>
    <?php endif;?>

    <div class="cards-list">
        <?php foreach ($doctors as $doc):?>
            <a class="card" href="/local/homeworks/homework3/Doctor/<?=$doc["NAME"]?>">
                <div class="fio">
                    <?=$doc['LAST_NAME']?>
                    <?=$doc['FIRST_NAME']?>
                    <?=$doc['MIDDLE_NAME']?>
                </div>
            </a>
        <?php endforeach;?>
    </div>

    <?php if(is_array($doctor) && sizeof($doctor) > 0 && $action != 'edit'):?>
    <div class="doctor-page">
        <h2><?=$doctor['LAST_NAME']." ".$doctor['FIRST_NAME']." ".$doctor['MIDDLE_NAME']?></h2>
        <h3>Процедуры:</h3>
        <ul>
            <?php foreach ($procs as $proc):?>
                <li><?=$proc['ELEMENT_NAME']?></li>
            <?php endforeach;?>
        </ul>
    </div>
    <?php endif;?>

    <?php if ($action == 'new' || $action == 'edit'):?>
    <form method="POST">
        <h2 style="text-align:center">Данные врача</h2>
        <div class="doctor-add-form">

            <?php if(isset($data['ID'])):?>
                <input type="hidden" name="ID" value="<?=$data['ID']?>" />
            <?php endif;?>

            <input type="text" name="NAME" placeholder="Название страницы врача (фамилия латиницей)" value = "<?=$data['NAME']??''?>" />
            <input type="text" name="LAST_NAME" placeholder="Фамилия врача" value = "<?=$data['LAST_NAME']??''?>" />
            <input type="text" name="FIRST_NAME" placeholder="Имя врача" value = "<?=$data['FIRST_NAME']??''?>" />
            <input type="text" name="MIDDLE_NAME" placeholder="Отчество врача" value = "<?=$data['MIDDLE_NAME']??''?>" />

            <select multiple name="PROC_IDS_MULTI[]">
                <option value="" selected disabled>Процедуры</option>
                <?php foreach ($proc_options as $proc):?>
                    <option value="<?=$proc['ID']?>" <?php if (isset($data['PROC_IDS_MULTI']) && in_array($proc['ID'], $data['PROC_IDS_MULTI'])):?>selected<?php endif;?> > <?=$proc['ELEMENT_NAME']?>
                    </option>
                <?php endforeach; ?>
            </select>

            <input type="submit" name="doctor-submit" value="Сохранить"/>
        </div>
    </form>
    <?php endif;?>

    <?php if ($action == 'newproc'):?>
        <form method="POST">
            <h2 style ="text-align:center;">Добавить процедуру</h2>
            <div class="doctor-add-form">
                <input type="text" name="NAME" placeholder="Название процедуры"/>
                <input type="submit" name="proc-submit" value="Сохранить"/>
            </div>
        </form>
    <?php endif;?>

</section>
