/**
 * @author			Julian Bogdani <jbogdani@gmail.com>
 * @copyright		BraDypUS, Julian Bogdani <jbogdani@gmail.com>
 * @license			See file LICENSE distributed with this code
 */

var import_geodata = {
  
  init: function(){
    core.open({
      title: core.tr('import_geodata'),
      obj: 'import_geodata_ctrl',
      method: 'start',
      buttons:[
        {
          text: core.tr('close'),
          click: 'close',
          addclass: 'btn-info'
        }
      ]
    }, 'modal');
  }
  
};