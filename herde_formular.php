<?php
/** Gemeinsame Felder für "Herde gründen" und "Einstellungen der Herde". Erwartet $w. */
?>
<div class="feld"><label for="name"><?= te('herde.name') ?></label>
  <input id="name" name="name" required minlength="3" maxlength="60" value="<?= e($w['name']) ?>"></div>
<div class="feld"><label for="region"><?= te('herde.region') ?></label>
  <input id="region" name="region" maxlength="100" value="<?= e($w['region']) ?>"></div>
<div class="feld"><label for="beschreibung"><?= te('herde.beschreibung') ?></label>
  <textarea id="beschreibung" name="beschreibung" rows="4" maxlength="2000"><?= e($w['beschreibung']) ?></textarea></div>
<fieldset class="feld">
  <legend><?= te('herde.sichtbarkeit') ?></legend>
  <?php foreach (['listed', 'secret'] as $o): ?>
    <label class="wahl"><input type="radio" name="sichtbarkeit" value="<?= $o ?>" <?= $w['sichtbarkeit'] === $o ? 'checked' : '' ?>> <?= te('herde.' . $o) ?></label>
  <?php endforeach; ?>
</fieldset>
<fieldset class="feld">
  <legend><?= te('herde.beitritt') ?></legend>
  <?php foreach (['open', 'request', 'invite'] as $o): ?>
    <label class="wahl"><input type="radio" name="beitritt" value="<?= $o ?>" <?= $w['beitritt'] === $o ? 'checked' : '' ?>> <?= te('herde.' . $o) ?></label>
  <?php endforeach; ?>
</fieldset>
