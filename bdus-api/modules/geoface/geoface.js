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
  init: function(tb, sql){
		if ($('#map').length > 0){
      core.message(core.tr('map_already_opened'));
      return;
    }
    G.queue = [
			'getData',
			'loadLeaflet',
			'loadLDraw',
			'loadLOmnivore',
			'loadGoogle',
			'loadGoogleMutant',
			'buildMap'];
    G.param.tb = tb;
    G.param.where = sql;
    G.runQueue();
		return this;
  },

	/**
	 * Calls first function in queue array and removes it from array
	 */
  runQueue: function(){
    var fn = G.queue[0];
    G.queue.splice(0, 1);
		if(typeof G[fn] !== 'undefined'){
			G[fn]();
		}
  },


	/**
	 * Gets data from database, sets G.geoJSON, G.metadata, G.otherLayers and runs next function in queue
	 */
	getData: function(){

    core.getJSON('geoface_ctrl', 'getGeoJson', {tb: G.param.tb, where: G.param.where}, false, function(data){

      if (data.status === 'error'){
        core.message(data.text, 'error', true);
				return;
			}

      if (data.status === 'warning'){
        core.message(core.tr('no_geodata_present_create'), 'warning', true);
      }

      // set geoJSON
      G.geoJSON = data.data;

      // set metadata
      G.metadata = data.metadata;

      G.runQueue();

    });
  },

	/**
	 * Loads GoogleMaps APi
	 */
  loadGoogle: function(){
    if (typeof G.metadata.gmapskey === 'undefined') {
      console.log('*** GoogleMaps key missing: if you want to use google layers please enter the key for table ' + G.metadata.tb);
      G.runQueue();
      return;
    }
    if ($.inArray('google', G.metadata.layers.excludeWeb) === -1 && typeof(google) == 'undefined'){
      console.log('Google load');
      $.getScript('https://maps.googleapis.com/maps/api/js?key='+ G.metadata.gmapskey, function(){
				G.runQueue();
			}).fail(function(jqxhr, settings, exception) {
				console.log('google', exception);
			});
    } else {
      G.runQueue();
    }
  },

	/**
	 * Loads Leaflet.GoogleMutant plugin
	 */
  loadGoogleMutant: function(){
    if (typeof google !== 'undefined' && typeof L.GridLayer.GoogleMutant == 'undefined'){
      $.getScript('./modules/geoface/leaflet-googleMutant/Leaflet.GoogleMutant.js', function(){
        G.runQueue();
      }).fail(function(jqxhr, settings, exception) {
					console.log('googleMutant', exception);
        }
      );
    } else {
      G.runQueue();
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
        G.runQueue();
      }).fail(function(jqxhr, settings, exception) {
				console.log('L', exception);
      });
    } else {
      G.runQueue();
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
        G.runQueue();
      }).fail(function(jqxhr, settings, exception) {
				console.log('L.Draw', exception);
      });
    } else {
      G.runQueue();
    }
  },

	/**
	 * Loads Leaflet.Omnivore
	 */
  loadLOmnivore: function(){

    if (typeof omnivore === 'undefined'){
      $.getScript('./modules/geoface/leaflet-omnivore/leaflet-omnivore.min.js', function(){
        G.runQueue();
      }).fail(function(jqxhr, settings, exception) {
				console.log('Omnivore', exception);
			});
    } else {
      G.runQueue();
    }
  },

  buildMap: function(){
    core.open({
      html: '<div id="map_container"><div id="map"></div></div>',
      unique: true,
      title: core.tr('GeoFace'),
      loaded: function(){
        //starts map
        G.startL();
        // dynamic resize
        $(window).on('resize', function(){
          G.resize();
        });
      }
    });
  },

  startL: function(){

		// Start map object
		G.map = new L.map('map');

    var availableBaseMaps = {
      "OSM": new L.tileLayer('http://{s}.tile.osm.org/{z}/{x}/{y}.png').addTo(G.map),
      "AWMC" : L.tileLayer('http://{s}.tiles.mapbox.com/v3/isawnyu.map-knmctlkh/{z}/{x}/{y}.png'),
      // http://commons.pelagios.org/2012/09/a-digital-map-of-the-roman-empire/
      "Imperium\/Pelagios" : L.tileLayer('http://pelagios.org/tilesets/imperium/{z}/{x}/{y}.png'),
      "Imperium\/DARE" : L.tileLayer('http://dare.ht.lu.se/tiles/imperium/{z}/{x}/{y}.png')
    };

    if (typeof L.gridLayer.googleMutant !== 'undefined') {
      availableBaseMaps['Google Satellite'] =	L.gridLayer.googleMutant({ type:'satellite'});
      availableBaseMaps['Google Roadmap'] =		L.gridLayer.googleMutant({ type:'roadmap'});
      availableBaseMaps['Google Terrain'] =		L.gridLayer.googleMutant({ type:'terrain' });
      availableBaseMaps['Google Hybrid'] =			L.gridLayer.googleMutant({ type:'hybrid'});
    }

    // baseMap object contains all basemaps
    var baseMaps = {};

    for (var k in availableBaseMaps) {
      if (
        availableBaseMaps.hasOwnProperty(k) &&
        (
          typeof G.metadata.layers.excludeWeb === 'undefined' ||
          G.metadata.layers.excludeWeb.indexOf(k) < 0
        )
      ) {
        baseMaps[k] = availableBaseMaps[k];
      }
    }

    // Start Google
    // if ($.inArray('google', G.metadata.layers.excludeWeb) === -1 && typeof L.gridLayer.googleMutant !== 'undefined'){
    //   baseMaps['Google Satellite'] =	L.gridLayer.googleMutant({ type:'satellite'});
    //   baseMaps['Google Roadmap'] =		L.gridLayer.googleMutant({ type:'roadmap'});
    //   baseMaps['Google Terrain'] =		L.gridLayer.googleMutant({ type:'terrain' });
    //   baseMaps['Google Hybrid'] =			L.gridLayer.googleMutant({ type:'hybrid'});
    // }

    // Main, database, overlay vector layer
		G.overlay[G.metadata.tb] = L.geoJson(G.geoJSON, {
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
			  html += '<span class="btn btn-info btn-xs" onclick="api.record.read(\'' + G.metadata.tb_id + '\', [' + feature.properties.id + '])">' + core.tr('read') + '</span>';
			  layer.bindPopup(html);
		  }

		})
		.addTo(G.map);

    if (typeof G.metadata.layers.local !== 'undefined' && typeof omnivore !== 'undefined'){
      $.each(G.metadata.layers.local, function (i, lay){
        var ext = lay.id.split('.').pop().toLowerCase();

        switch (ext) {
          case 'csv':
          case 'gpx':
          case 'kml':
          case 'wkt':
          case 'topojson':
          case 'geojson':
            G.overlay[lay.name] = omnivore[ext](lay.id, null, L.geoJson(null, {
              style: function(feature) {
                return lay.style;
              },
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
            .addTo(G.map);
            break;
          default:
            console.log('Unknown extension: ' + ext);
            break;
        }
      });

    }

    //Add other layers, both basemaps and overlays to map
    G.map.addControl(new L.Control.Layers( baseMaps, G.overlay, {}));


    // Edit controls
    if (G.metadata.canUserEdit && typeof L.Control.Draw !== 'undefined'){
      // Create draw control
      var drawControl = new L.Control.Draw({
				draw: { marker: false },
				edit: { featureGroup: G.overlay[G.metadata.tb] }
      });
      // Add draw control to map
      G.map.addControl(drawControl);
      G.editListeners(G.overlay[G.metadata.tb]);
    }

    // Fit map to window size
    G.resize();

    // Zoom and pan map to max extent of vector layer
    if(typeof G.overlay[G.metadata.tb] === 'undefined' || $.isEmptyObject(G.overlay[G.metadata.tb]._layers)){
      G.map.setView([38.82259, -2.8125], 3);
    } else {
      G.map.fitBounds(G.overlay[G.metadata.tb].getBounds());
    }
  },

  editListeners: function(vector_layer){

    // CREATED
    G.map.on('draw:created', function(e){

      api.link.add_ui(
        //success function
        function(tb, id, dia){
          core.getJSON('geoface_ctrl', 'saveNew', false, {tb: tb, id: id, coords: G.toWKT(e.layer)}, function(data){
            core.message(data.text, data.value);
            e.layer.feature = {properties: {geo_id: data.id}};
          });
          $('#modal').modal('hide');

          vector_layer.addLayer(e.layer);
        },
        false,
        true,
        G.metadata.tb_id
      );
    });



    // EDITED
    G.map.on('draw:edited', function(e){
      var post_data = [];
      e.layers.eachLayer(function (layer) {
				post_data.push({
          id: layer.feature.properties.geo_id,
          coords: G.toWKT(layer)
        });
      });
      core.getJSON('geoface_ctrl', 'update', false, {geodata: post_data}, function(data){
        core.message(data.text, data.status);
      });
    });


    // DELETED
    G.map.on('draw:deleted', function(e){

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
      G.map.invalidateSize();
    },

    /**
     * Return WKT string for layer object
     * https://gist.github.com/bmcbride/4248238
     * https://groups.google.com/forum/#!msg/leaflet-js/ZAOs2TyKLwI/YqhadbsfL8gJ
     */
    toWKT: function (layer) {
			var lng, lat, coords = [];
      if (layer instanceof L.Polygon || layer instanceof L.Polyline) {
        var latlngs = layer.getLatLngs();
        for (var i = 0; i < latlngs.length; i++) {
          coords.push(latlngs[i].lng + " " + latlngs[i].lat);
          if (i === 0) {
            lng = latlngs[i].lng;
            lat = latlngs[i].lat;
          }
        }
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

var G = geoface;
