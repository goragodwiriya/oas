function initDownload(id) {
  var doDelete = function() {
    if (confirm(trans('You want to XXX ?').replace(/XXX/, trans('delete')))) {
      send("index.php/download/model/action/delete", 'id=' + this.id, doFormSubmit, this);
    }
  };
  forEach($E(id).querySelectorAll('.icon-delete'), function() {
    callClick(this, doDelete);
  });
}
