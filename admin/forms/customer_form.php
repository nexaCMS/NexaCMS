<div class="row">
  <div class="col-sm-6 form-group">
    <label>First Name *</label>
    <input type="text" name="f_name" class="form-control" value="<?= esc_attr($edit ? ($customer['f_name'] ?? '') : '') ?>" required>
  </div>
  <div class="col-sm-6 form-group">
    <label>Last Name *</label>
    <input type="text" name="l_name" class="form-control" value="<?= esc_attr($edit ? ($customer['l_name'] ?? '') : '') ?>" required>
  </div>
</div>
<div class="row">
  <div class="col-sm-4 form-group">
    <label>Gender</label>
    <?php $g = strtolower((string) ($edit ? ($customer['gender'] ?? 'male') : 'male')); ?>
    <select name="gender" class="form-control">
      <option value="male" <?= $g === 'male' ? 'selected' : '' ?>>Male</option>
      <option value="female" <?= $g === 'female' ? 'selected' : '' ?>>Female</option>
      <option value="other" <?= $g === 'other' ? 'selected' : '' ?>>Other</option>
    </select>
  </div>
  <div class="col-sm-4 form-group">
    <label>Phone</label>
    <input type="text" name="phone" class="form-control" value="<?= esc_attr($edit ? ($customer['phone'] ?? '') : '') ?>">
  </div>
  <div class="col-sm-4 form-group">
    <label>Email</label>
    <input type="email" name="email" class="form-control" value="<?= esc_attr($edit ? ($customer['email'] ?? '') : '') ?>">
  </div>
</div>
<div class="row">
  <div class="col-sm-8 form-group">
    <label>Address</label>
    <input type="text" name="address" class="form-control" value="<?= esc_attr($edit ? ($customer['address'] ?? '') : '') ?>">
  </div>
  <div class="col-sm-4 form-group">
    <label>Date of Birth</label>
    <input type="date" name="date_of_birth" class="form-control" value="<?= esc_attr($edit ? ($customer['date_of_birth'] ?? '') : '') ?>">
  </div>
</div>
<button type="submit" class="btn btn-primary"><i class="fa fa-save"></i> <?= $edit ? 'Update Customer' : 'Save Customer' ?></button>
<a href="customers.php" class="btn btn-default">Cancel</a>
