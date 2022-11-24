/**
* @author      Julian Bogdani <jbogdani@gmail.com>
* @copyright    BraDypUS, Julian Bogdani <jbogdani@gmail.com>
* @license      See file LICENSE distributed with this code
*/

var geoface  = {
  
  geoJSON: {},
  metadata: {},
  map: {},
  param: {},
  overlay: {},
  
  
  /**
  * Loads libraries and starts the map
  * @param  {String} tb  Reference table name
  * @param  {String} sql Query string
  * @return {Object}     Self object
  */
  init: function(tb, obj_encoded){
    if ($('#map').length > 0){
      core.message(core.tr('map_already_opened'));
      return;
    }
    geoface.queue = [
      'getData',
      'loadLeaflet',
      'loadLDraw',
      'loadLOmnivore',
      'loadGoogle',
      'loadGoogleMutant',
      'buildMap'
    ];
    geoface.param.tb = tb;
    geoface.param.obj_encoded = obj_encoded;
    geoface.runQueue();
    return this;
  },
  
  /**
  * Calls first function in queue array and removes it from array
  */
  runQueue: function(){
    var fn = geoface.queue[0];
    geoface.queue.splice(0, 1);
    if(typeof geoface[fn] !== 'undefined'){
      geoface[fn]();
    }
  },
  
  
  /**
  * Gets data from database, sets geoface.geoJSON, geoface.metadata, geoface.otherLayers and runs next function in queue
  */
  getData: function(){
    
    core.getJSON(
      'geoface_ctrl', 
      'getGeoJson', 
      {
        tb: geoface.param.tb, 
        obj_encoded: geoface.param.obj_encoded
      }, 
      false, 
      function(data){
        if (data.status === 'error'){
          core.message(data.text, 'error', true);
          return;
        }
        
        if (data.status === 'warning'){
          core.message(core.tr('no_geodata_present_create'), 'warning', true);
        }
        
        // set geoJSON
        geoface.geoJSON = data.data;
        
        // set metadata
        geoface.metadata = data.metadata;
        
        geoface.runQueue();
      }
    );
  },
    
  /**
  * Loads GoogleMaps APi
  */
  loadGoogle: function(){
    if (typeof geoface.metadata.gmapskey === 'undefined') {
      console.log('*** GoogleMaps key missing: if you want to use google layers please enter the key for table ' + geoface.metadata.tb);
      geoface.runQueue();
      return;
    }
    if (typeof(google) == 'undefined'){
      $.getScript('https://maps.googleapis.com/maps/api/js?key='+ geoface.metadata.gmapskey, function(){
        geoface.runQueue();
      }).fail(function(jqxhr, settings, exception) {
        console.log('google', exception);
      });
    } else {
      geoface.runQueue();
    }
  },
  
  /**
  * Loads Leaflet.GoogleMutant plugin
  */
  loadGoogleMutant: function(){
    if (typeof google !== 'undefined' && typeof L.GridLayer.GoogleMutant == 'undefined'){
      $.getScript('./modules/geoface/leaflet-googleMutant/Leaflet.GoogleMutant.js', function(){
        geoface.runQueue();
      }).fail(function(jqxhr, settings, exception) {
        console.log('googleMutant', exception);
      }
      );
    } else {
      geoface.runQueue();
    }
  },
  
  /**
  * Loads leaflet
  */
  loadLeaflet: function(){
    
    if($('head').find('link[href="./modules/geoface/leaflet/leaflet.css"]').length < 1){
      $('head').append( $('<link />').attr({'type':'text/css', 'rel':'stylesheet', 'href':'./modules/geoface/leaflet/leaflet.css'}) );
    }
    
    if (typeof L == 'undefined'){
      $.getScript('./modules/geoface/leaflet/leaflet.js', function(){
        L.Icon.Default.imagePath = './modules/geoface/leaflet/images/';
        geoface.runQueue();
      }).fail(function(jqxhr, settings, exception) {
        console.log('L', exception);
      });
    } else {
      geoface.runQueue();
    }
  },
  
  /**
  * Loads Leaflet.Draw
  */
  loadLDraw: function(){
    
    if($('head').find('link[href="./modules/geoface/leaflet-draw/leaflet.draw.css"]').length < 1){
      $('head').append( $('<link />').attr({'type':'text/css', 'rel':'stylesheet', 'href':'./modules/geoface/leaflet-draw/leaflet.draw.css'}) );
    }
    
    if (typeof L.drawVersion === 'undefined'){
      $.getScript('./modules/geoface/leaflet-draw/leaflet.draw.js', function(){
        geoface.runQueue();
      }).fail(function(jqxhr, settings, exception) {
        console.log('L.Draw', exception);
      });
    } else {
      geoface.runQueue();
    }
  },
  
  /**
  * Loads Leaflet.Omnivore
  */
  loadLOmnivore: function(){
    if (typeof omnivore === 'undefined'){
      $.getScript('./modules/geoface/leaflet-omnivore/leaflet-omnivore.min.js', function(){
        geoface.runQueue();
      }).fail(function(jqxhr, settings, exception) {
        console.log('Omnivore', exception);
      });
    } else {
      geoface.runQueue();
    }
  },
  
  buildMap: function(){
    core.open({
      html: '<div id="map_container"><div id="map"></div></div>',
      unique: true,
      title: core.tr('GeoFace'),
      loaded: function(){
        //starts map
        geoface.startL();
        // dynamic resize
        $(window).on('resize', function(){
          geoface.resize();
        });
      }
    });
  },
  
  startL: function(){
    // Start map object
    geoface.map = new L.map('map');
    
    var availableBaseMaps = {
      "OSM": new L.tileLayer('https://{s}.tile.osm.org/{z}/{x}/{y}.png').addTo(geoface.map)
    };
    if (typeof L.gridLayer.googleMutant !== 'undefined' && geoface.metadata.gmapskey !== '') {
      availableBaseMaps['Google Satellite'] =	L.gridLayer.googleMutant({ type:'satellite'});
      availableBaseMaps['Google Roadmap'] =		L.gridLayer.googleMutant({ type:'roadmap'});
      availableBaseMaps['Google Terrain'] =		L.gridLayer.googleMutant({ type:'terrain' });
      availableBaseMaps['Google Hybrid'] =		L.gridLayer.googleMutant({ type:'hybrid'});
    }
    
    // baseMap object contains all basemaps
    var baseMaps = {};
    
    for (var k in availableBaseMaps) {
      if ( availableBaseMaps.hasOwnProperty(k) ) {
        baseMaps[k] = availableBaseMaps[k];
      }
    }
      
    // Main, database, overlay vector layer
    geoface.overlay[geoface.metadata.tb] = L.geoJson(geoface.geoJSON, {
      pointToLayer: function(feature, latlng){
        return new L.CircleMarker(latlng, {
          color: '#f00',
          fillColor: '#ff0',
          weight: 4,
          opacity: 0.7,
          radius: 7
        });
      },
      onEachFeature: function (feature, layer) {
        var html = '';
        $.each(feature.properties, function(key, val){
          if (key !== 'id' && key !== 'geo_id'){
            html += key + ': <strong>' + val + '</strong><br />';
          }
        });
        html += '<span class="btn btn-info btn-xs" onclick="api.record.read(\'' + geoface.metadata.tb_id + '\', [' + feature.properties.id + '])">' + core.tr('read') + '</span>';
        layer.bindPopup(html);
      }
      
    })
    .addTo(geoface.map);

    if (typeof geoface.metadata.custom_layers !== 'undefined'){
      $.each(geoface.metadata.custom_layers, function (i, lay){
        // Handle local layers
        if (lay.type === 'local' && typeof omnivore !== 'undefined'){
          const path_parts = lay.path.split('.');
          const ext = path_parts[path_parts.length - 1];

          switch(ext){
            case 'csv':
            case 'gpx':
            case 'kml':
            case 'wkt':
            case 'topojson':
            case 'geojson':
              geoface.overlay[lay.label] = omnivore[ext](`${geoface.metadata.baseLocalPath}${lay.path}`, null, L.geoJson(null, {
                onEachFeature: function (feature, layer) {
                  var html = '';
                  $.each(feature.properties, function(key, val){
                    if (val){
                      html += key + ': <strong>' + val + '</strong><br />';
                    }
                  });
                  layer.bindPopup(html);
                }
              }))
              .addTo(geoface.map);
              break;
            default:
            console.log('Unknown extension: ' + ext);
            break;
          }

        // Handle WMS
        } else if (lay.type === 'wms'){
          if (lay.layertype === 'base'){
            baseMaps[lay.label] = L.tileLayer.wms(lay.path, {
              layers: lay.wmslayers
            });
          } else if (lay.layertype === 'overlay'){
            geoface.overlay[lay.label] = L.tileLayer.wms(lay.path, {
              layers: lay.wmslayers
            });
          }
        } else if (lay.type === 'tiles'){
          if (lay.layertype === 'base'){
            baseMaps[lay.label] = L.tileLayer(lay.path);
          } else if (lay.layertype === 'overlay'){
            geoface.overlay[lay.label] = L.tileLayer(lay.path);
          }
        }

      });
      
      // Handle TMS
    }
      
    
    //Add other layers, both basemaps and overlays to map
    geoface.map.addControl(new L.Control.Layers( baseMaps, geoface.overlay, {}));
      
      
    // Edit controls
    if (geoface.metadata.canUserEdit && typeof L.Control.Draw !== 'undefined'){
      // Create draw control
      var drawControl = new L.Control.Draw({
        draw: { 
          marker: false,
          circle: false,
        },
        edit: { featureGroup: geoface.overlay[geoface.metadata.tb] }
      });
      // Add draw control to map
      geoface.map.addControl(drawControl);
      geoface.editListeners(geoface.overlay[geoface.metadata.tb]);
    }
      
    // Fit map to window size
    geoface.resize();
      
    // Zoom and pan map to max extent of vector layer
    if(
      typeof geoface.overlay[geoface.metadata.tb] === 'undefined' 
      || 
      $.isEmptyObject(geoface.overlay[geoface.metadata.tb]._layers)
    ){
        geoface.map.setView([38.82259, -2.8125], 3);
    } else {
        geoface.map.fitBounds(geoface.overlay[geoface.metadata.tb].getBounds());
    }
  },
    
  editListeners: function(vector_layer){
    
    // CREATED
    geoface.map.on('draw:created', function(e){
      api.link.add_ui(
        //success function
        function(tb, id){
          core.getJSON(
            'geoface_ctrl', 
            'saveNew', 
            false, 
            {
              tb: tb, 
              id: id, 
              coords: geoface.toWKT(e.layer)
            }, 
            function(data){
              core.message(data.text, data.value);
              e.layer.feature = {properties: {geo_id: data.id}};
            }
          );
          $('#modal').modal('hide');
          
          vector_layer.addLayer(e.layer);
        },
        false,
        true,
        geoface.metadata.tb_id
        );
      });
      
      
      
      // EDITED
      geoface.map.on('draw:edited', function(e){
        var post_data = [];
        e.layers.eachLayer(function (layer) {
          post_data.push({
            id: layer.feature.properties.geo_id,
            coords: geoface.toWKT(layer)
          });
        });
        core.getJSON('geoface_ctrl', 'update', false, {geodata: post_data}, function(data){
          core.message(data.text, data.status);
        });
      });
      
      
      // DELETED
      geoface.map.on('draw:deleted', function(e){
        
        var id_arr = [];
        e.layers.eachLayer(function (layer) {
          id_arr.push(layer.feature.properties.geo_id);
        });
        
        core.open({
          html: '<h2>' + core.tr('confirm_erase_feature') + '</h2>',
          title: core.tr('attention'),
          buttons:[
            {
              text: core.tr('erase'),
              click: function(){
                core.getJSON('geoface_ctrl', 'erase', false, {ids: id_arr}, function(data){
                  core.message(data.text, data.status);
                  layout.dialog.close();
                });
              }
            },
            {
              text: core.tr('cancel'),
              action: 'close'
            }
          ]
        }, 'modal');
      });
    },
      
  /**
  * Resizes map container to fit window
  */
  resize: function(){
    $('#map_container').css({
      'width':  '100%',
      'height': ($(window).height()-70)
    });
    $('#map').css({
      'height': '100%'
    });
    geoface.map.invalidateSize();
  },
  
  /**
  * Return WKT string for layer object
  * https://gist.github.com/bmcbride/4248238
  * https://groups.google.com/forum/#!msg/leaflet-js/ZAOs2TyKLwI/YqhadbsfL8gJ
  */
  toWKT: function (layer) {
    var lng, lat, coords = [];
    if (layer instanceof L.Polygon || layer instanceof L.Polyline) {
      
      var latlngs = Array.isArray(layer.getLatLngs()[0]) ? layer.getLatLngs()[0] : layer.getLatLngs();

      for (var i = 0; i < latlngs.length; i++) {
        var latlngs1 = latlngs[i];
        if (latlngs1.length){
          for (var j = 0; j < latlngs1.length; j++) {
            coords.push(latlngs1[j].lng + " " + latlngs1[j].lat);
            if (j === 0) {
              lng = latlngs1[j].lng;
              lat = latlngs1[j].lat;
            }
          }
        } else {
          coords.push(latlngs[i].lng + " " + latlngs[i].lat);
          if (i === 0) {
            lng = latlngs[i].lng;
            lat = latlngs[i].lat;
          }
        }
      };
      if (layer instanceof L.Polygon) {
        return "POLYGON((" + coords.join(",") + "," + lng + " " + lat + "))";
      } else if (layer instanceof L.Polyline) {
        return "LINESTRING(" + coords.join(",") + ")";
      }
    } else if (layer instanceof L.Marker || layer instanceof L.CircleMarker) {
      return "POINT(" + layer.getLatLng().lng + " " + layer.getLatLng().lat + ")";
    }
  }
  
};