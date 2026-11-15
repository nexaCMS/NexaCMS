<form method="post" action="install.php?step=3">
  <h4>1. Database Backend</h4>
  <div class="row">
    <div class="col-sm-6 form-group">
      <label>Database Driver</label>
      <select name="db_type" id="inst_db_type" class="form-control" onchange="document.getElementById('inst_sql_fields').style.display=(this.value==='mysql'||this.value==='pgsql')?'block':'none';">
        <option value="csv">CSV Flat-File</option>
        <option value="sqlite">SQLite 3 (Single File)</option>
        <option value="json">JSON Store</option>
        <option value="array">PHP Array Store</option>
        <option value="mysql">MySQL / MariaDB</option>
        <option value="pgsql">PostgreSQL</option>
      </select>
    </div>
    <div class="col-sm-6 form-group">
      <label>Database Name</label>
      <input type="text" name="db_name" class="form-control" value="db" required>
    </div>
  </div>

  <div id="inst_sql_fields" style="display:none;">
    <div class="row">
      <div class="col-sm-8 form-group">
        <label>SQL Host</label>
        <input type="text" name="db_host" class="form-control" value="127.0.0.1">
      </div>
      <div class="col-sm-4 form-group">
        <label>Port</label>
        <input type="text" name="db_port" class="form-control" placeholder="3306 / 5432">
      </div>
    </div>
    <div class="row">
      <div class="col-sm-6 form-group">
        <label>DB User</label>
        <input type="text" name="db_user" class="form-control" value="root">
      </div>
      <div class="col-sm-6 form-group">
        <label>DB Password</label>
        <input type="password" name="db_pass" class="form-control">
      </div>
    </div>
  </div>

  <hr>
  <h4>2. Site &amp; Administrator Account</h4>
  <div class="row">
    <div class="col-sm-6 form-group">
      <label>Site Name</label>
      <input type="text" name="site_name" class="form-control" value="NexaCMS CMS" required>
    </div>
    <div class="col-sm-6 form-group">
      <label>Tagline</label>
      <input type="text" name="tagline" class="form-control" value="Fast Standalone Multi-Database PHP CMS">
    </div>
  </div>
  <div class="row">
    <div class="col-sm-4 form-group">
      <label>Admin Username</label>
      <input type="text" name="admin_user" class="form-control" value="admin" required>
    </div>
    <div class="col-sm-4 form-group">
      <label>Admin Email</label>
      <input type="email" name="admin_email" class="form-control" value="admin@localhost" required>
    </div>
    <div class="col-sm-4 form-group">
      <label>Admin Password</label>
      <input type="password" name="admin_pass" class="form-control" value="password" required>
      <p class="help-block">At least 8 characters, and it must not contain the admin username. (Configurable later under Settings → Password Policy.)</p>
    </div>
  </div>

  <button type="submit" class="btn btn-success btn-block"><i class="fa fa-check"></i> Install NexaCMS Now</button>
</form>
