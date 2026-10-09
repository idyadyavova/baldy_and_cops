<?php
// Кусок формы «кто видит проект» — общий для загрузки и настроек. Ожидает массив $form.
?>
<fieldset>
  <legend>Кто видит</legend>
  <div class="radios">
    <label class="check"><input type="radio" name="visibility" value="key"<?= admin_checked($form['visibility'] === 'key') ?>><span><b>По ключу</b><small>открывается только по секретной ссылке; без неё — «страницы нет»</small></span></label>
    <label class="check"><input type="radio" name="visibility" value="private"<?= admin_checked($form['visibility'] === 'private') ?>><span><b>Только мне</b><small>открывается из панели по временной ссылке на 12 часов</small></span></label>
    <label class="check"><input type="radio" name="visibility" value="public"<?= admin_checked($form['visibility'] === 'public') ?>><span><b>Всем</b><small>по обычной ссылке; можно встроить в пост</small></span></label>
  </div>
  <label class="check"><input type="checkbox" name="listed" value="1"<?= admin_checked((bool)$form['listed']) ?>><span><b>Показывать на главной</b><small>в списке «Эксперименты»; только для открытых всем</small></span></label>
  <label class="check"><input type="checkbox" name="sandbox" value="1"<?= admin_checked((bool)$form['sandbox']) ?>><span><b>Изоляция (рекомендуется)</b><small>проект работает в песочнице и не видит панель и cookie сайта. Выключайте, только если проекту нужны localStorage или IndexedDB, и только для своих файлов, которым доверяете.</small></span></label>
</fieldset>
