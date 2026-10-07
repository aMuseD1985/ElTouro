<?php
/** Shared fields for "start a crew" and "crew settings". Expects $w. Not reachable directly (.htaccess: ^_). */
?>
<div class="field"><label for="name"><?= te('crew.name') ?></label>
  <input id="name" name="name" required minlength="3" maxlength="60" value="<?= e($w['name']) ?>"></div>
<div class="field"><label for="region"><?= te('crew.region') ?></label>
  <input id="region" name="region" maxlength="100" value="<?= e($w['region']) ?>"></div>
<div class="field"><label for="description"><?= te('crew.description') ?></label>
  <textarea id="description" name="description" rows="4" maxlength="2000"><?= e($w['description']) ?></textarea></div>
<fieldset class="field">
  <legend><?= te('crew.visibility') ?></legend>
  <?php foreach (['listed', 'secret'] as $o): ?>
    <label class="choice"><input type="radio" name="visibility" value="<?= $o ?>" <?= $w['visibility'] === $o ? 'checked' : '' ?>> <?= te('crew.' . $o) ?></label>
  <?php endforeach; ?>
</fieldset>
<fieldset class="field">
  <legend><?= te('crew.joining') ?></legend>
  <?php foreach (['open', 'request', 'invite'] as $o): ?>
    <label class="choice"><input type="radio" name="joining" value="<?= $o ?>" <?= $w['joining'] === $o ? 'checked' : '' ?>> <?= te('crew.' . $o) ?></label>
  <?php endforeach; ?>
</fieldset>
