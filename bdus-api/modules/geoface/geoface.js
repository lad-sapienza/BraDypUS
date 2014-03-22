/**
 * steps:
 * 	1) load data
 * 		a) load main
 * 		b) load json
 * 	2) load Google (?)
 * 	3) load OL
 */
var geoface = {
		init: function(tb, query){
			if ($('#map').length > 0){
				core.message(core.tr('map_already_opened'));
				return;
			} else {
				geoface.getData(tb, query);
			}
		},
		
		geoJSON: {},
		otherLayers: [],
		map: {},
		metadata : {},
		internalEpsg: 900913,
		activeCtrl: '',
		activeCtrlObj: {},
	
		/**
		 * Gets geojson and other metadata from controller using table and where statement
		 * And loads googlemaps API, if required or OL script
		 * @param tb
		 * @param where
		 */
		getData: function(tb, where){
			core.getJSON('geoface_ctrl', 'getGeoJson', [tb, where], false, function(data){
				
				if (data.status == 'error'){
					core.message(data.text, 'error', true);
				} else {
					
					if (data.status == 'warning'){
						core.message(core.tr('no_geodata_present_create'), 'warning', true);
					}
					
					// set geoJSON
					geoface.geoJSON = data.data;
					
					// set metadata
					geoface.metadata = data.metadata;
					
					// load sub data
					if (typeof(geoface.metadata.layers.local) == 'object'){
						geoface.otherLayers = [];
						$.each(geoface.metadata.layers.local, function(index, vec){
							
							if (vec.id.match(/\.geojson/)){
								
								$.getJSON(vec.id, function(data2){
									geoface.otherLayers.push({
										'name'	: vec.name,
										'id'	: vec.id,
										'epsg'	: vec.epsg,
										'data'	: data2,
										'style'	: vec.style
									});
								
									if (index == (geoface.metadata.layers.local.length-1)){
										geoface.loadGM();
									}
								}).fail(
										  function(jqxhr, settings, exception) {
											console.log(vec.id, exception);
										  });
							} else {
								if (index == (geoface.metadata.layers.local.length-1)){
									geoface.loadGM();
								}
							}
						});
					} else {
						geoface.loadGM();
					}
				}
			});
		},
		/**
		 * Loads GoogleMap API if not loaded before and calls geoface.loadOL
		 */
		loadGM: function(){
			// if google is signed to be loaded in user preferences, just load it
			if ($.inArray('google', geoface.metadata.layers.excludeWeb) == -1 && typeof(google) == 'undefined'){
				$.getScript('http://maps.google.com/maps/api/js?v=3&sensor=false&callback=geoface.loadOL').fail(
						function(jqxhr, settings, exception) {
							   console.log('google', exception);
							}		
				);
			} else{
				geoface.loadOL();
			}
		},
		
		/**
		 * Loads Ol script, if not loaded before and runs geoface.showmap
		 */
		loadOL: function(){
			// loads css if not present
			if($('head').find('link[href="./modules/geoface/theme/default/style.css"]').length < 1){
				$('head').append( $('<link />').attr({'type':'text/css', 'rel':'stylesheet', 'href':'./modules/geoface/theme/default/style.css'}) );
			}
			//loads OL if not loaded
			if (typeof(OpenLayers) == 'undefined'){
				$.getScript('./modules/geoface/OpenLayers.js', function(){
					OpenLayers.ImgPath = './modules/geoface/img/';
					geoface.showmap();
				}).fail(
						function(jqxhr, settings, exception) {
							   console.log('OL', exception);
							}		
				);
			} else {
				geoface.showmap();
			}
		},
		
		/**
		 * Sets map controle
		 */
		toggleControl: function (ctrl) {
			
			geoface.activeCtrl = ctrl;
			if (ctrl == 'erase'){
				ctrl = 'select';
			}
			for(key in controls) {
	    		if (key == ctrl){
	    			controls[key].activate();
	    			
	    			geoface.activeCtrlObj = controls[key];
	    			
		    		if (key == 'point' || key == 'line' || key == 'polygon' || key == 'modify'){
		    			$('.showOnEdit').parents('li').show();
		    		} else {
		    			$('.showOnEdit').parents('li').hide();
		    		}
		    		
		    	} else {
		    		controls[key].deactivate();
		    	}
		    }
		},
		
		/**
		 * handles end of events on vectorl layer geometries
		 * @param event
		 */
		end: function (event){
			geometry = event.feature.geometry.clone();
			
			if (geoface.internalEpsg != geoface.metadata.epsg){
				
				geometry = geometry.transform(new OpenLayers.Projection('EPSG:' + geoface.internalEpsg), new OpenLayers.Projection('EPSG:' + geoface.metadata.epsg));
			}
			
			if (typeof(event.feature.data.geo_id) != 'undefined'){
				core.getJSON('geoface_ctrl', 'update', [event.feature.data.geo_id, geometry.toString()], false, function(data){
					core.message(data.text, data.status);
				});
			} else {
				api.link.add_ui(
						//success function
						function(tb, id, dia){
							core.getJSON('geoface_ctrl', 'saveNew', [tb, id, geometry.toString()], function(data){
								core.message(data.text, data.value);
								event.feature.data.id = id;
							});
							$('#modal').modal('hide');
						},
						// close function
						function(){
							event.feature.layer.removeFeatures(event.feature);
						}, true, geoface.metadata.tb_id);
					
			}
		},
		
		/**
		 * Handles on feature select event (shows popup)
		 * @param feature
		 * @returns
		 */
		onFeatureSelect: function(event){
			
			feature = event.feature;
			
			switch(geoface.activeCtrl){
			
			case 'erase':
				core.open({
					html: '<h2>' + core.tr('confirm_erase_feature') + '</h2>',
					title: core.tr('attention'),
					buttons:[
					         {
					        	 text: core.tr('erase'),
					        	 click: function(){
					        		 core.getJSON('geoface_ctrl', 'erase', [feature.data.geo_id], false, function(data){
					        			 core.message(data.text, data.status);
					        			 if (data.status == 'success'){
					        				 feature.layer.removeFeatures(feature);
					        			 }
					        		 });
					        		 layout.dialog.close();
					        	 }
					         },
					         {
					        	 text: core.tr('cancel'),
					        	 action: 'close'
					         }
					         ]
					
				}, 'modal');
				
				break;
				
			case 'select':
				var html = '';
				
			    $.each(feature.data, function(key, val){
			    	if (key != 'id' && key != 'geo_id'){
			    		html += key + ': <strong>' + val + '</strong><br />';
			    	}
			    });
			    html += '<div>';
			    html += ( (feature.layer.name == geoface.metadata.tb) ? '<span class="a btn btn-default" onclick="api.record.read(\'' + geoface.metadata.tb_id + '\', [' + feature.data.id + '])">' + core.tr('read') + '</span>' : '');
			    html += '</div>';
			    popup = new OpenLayers.Popup.FramedCloud('popup', feature.geometry.getBounds().getCenterLonLat(), null, html, null, true);
			    feature.popup = popup;
			    geoface.map.addPopup(popup);
				break;
				
			default:
					return;
				break;
			}
		},
		
		/**
		 * Handles on feture unselect (removes popup)
		 * @param feature
		 */
		onFeatureUnselect: function(event){
			switch(geoface.activeCtrl){
			
				case 'select':
					geoface.map.removePopup(event.feature.popup);
					event.feature.popup.destroy();
					event.feature.popup = null;
					break;
					
				default:
					return;
				break;

			}
		},
		
		addXY: function(x, y, layerEPSG, mapEPSG){
			if (layerEPSG != mapEPSG){
				var location = new OpenLayers.LonLat(x, y).transform(  new OpenLayers.Projection("EPSG:" + layerEPSG), new OpenLayers.Projection("EPSG:" + mapEPSG));
			} else {
				var location = {
						lon: x,
						lat: y
				};
			}
			
			if (geoface.activeCtrl == 'point'){
				var point = new OpenLayers.Geometry.Point(location.lon, location.lat);
				var point_ft = new OpenLayers.Feature.Vector(point, null, null);
				geoface.vector_layer.addFeatures(point_ft);
			} else{
				
				geoface.activeCtrlObj.insertXY(location.lon, location.lat);
			}
			
		},
		
		manualCoordinates: function(){
			core.open({
				html: '<div class="coordinates row">' +
					'<div class="col-sm-6">' + 
						'<input type="text" placeholder="x / easting / longitude" class="x" /> ' +
					'</div>' + 
					'<div class="col-sm-6">' + 
						'<input type="text" placeholder="y / northing / latitude" class="y" />' +
					'</div>' + 
					'<hr /><p class="lead">' + core.tr('crs') + ' <strong>EPSG:' + geoface.metadata.epsg + '</strong></p>' +
				'</div>',
				title: core.tr('add_coordinates'),
				buttons:[
				         {
				        	 text: core.tr('add'),
				        	 click: function(){
				        		 var x = $('#modal input.x').val(),
				        		 y = $('#modal input.y').val();
				        		 
				        		 if (!x || !y){
				        			 core.message(core.tr('x_y_required'), 'error');
				        		 } else {
				        			 geoface.addXY(x, y, geoface.metadata.epsg, geoface.internalEpsg);
				        			 $('#modal').modal('hide');
				        		 }
				        	 }
				         },
				         {
				        	 text: core.tr('close'),
				        	 action: 'close'
				         }
				         ]
			}, 'modal');
		},
		
		
		locationCoordinates: function(){
			navigator.geolocation.getCurrentPosition(
					
					function(o) {
						if( o.coords && o.coords.latitude && o.coords.longitude ) {
							
							geoface.addXY(o.coords.longitude, o.coords.latitude, '4326', geoface.internalEpsg);
							
						} else {
							console.log('some error');
						}
					},
					
					function(error){
						
						var errors = [ 'none', 'permission denied', 'position unavailable', 'timeout' ];
						
						core.message(errors[error.code], 'error');
						
					},
					
					{
						enableHighAccuracy: true,
						timeout: 27000
					}
					);
		},
		
		showmap: function(){
			// set main div
			div = $('<div />').attr('id', 'map_container').css({'height': '600px', 'width': '800px'}).append(
					$('<div />').attr('id', 'map_bar'),
					$('<div />').attr('id', 'map').css({'height': '100%', 'width': '100%'})
				).appendTo($('body'));
			
			// start map
			geoface.map = map = new OpenLayers.Map('map', { theme: null, projection: 'EPSG:' + geoface.internalEpsg });
			
			// start internal projection
			var internalProjection = new OpenLayers.Projection('EPSG:' + geoface.internalEpsg);
			
			// generic map controls
			map.addControl( new OpenLayers.Control.Navigation({zoomWheelEnabled:true}));
			map.addControl(new OpenLayers.Control.LayerSwitcher({'ascending':false}));
			map.addControl(new OpenLayers.Control.ScaleLine());
			map.addControl( new OpenLayers.Control.MousePosition());
			map.addControl(new OpenLayers.Control.OverviewMap());
			
			//OSM
			if ($.inArray('osm', geoface.metadata.layers.excludeWeb) == -1){
				var osm = new OpenLayers.Layer.OSM( "Open Street Map");
				map.addLayer(osm);
				
				var base = 'osm';
			}
			
			//GOOGLE
			if ($.inArray('google', geoface.metadata.layers.excludeWeb) == -1){
				// SATELLITE
				var gsat = new OpenLayers.Layer.Google("Google Satellite", {type: google.maps.MapTypeId.SATELLITE, numZoomLevels: 22});
				map.addLayer(gsat);
				
				// TERRAIN
				var gterr = new OpenLayers.Layer.Google("Google Terrain",{type: google.maps.MapTypeId.TERRAIN, visibility: false});
				map.addLayer(gterr);
				
				// STREET
				var gstr = new OpenLayers.Layer.Google("Google Streets",{visibility: false});
				map.addLayer(gstr);
				
				// HYBRID
				var ghyb = new OpenLayers.Layer.Google("Google Hybrid",{type: google.maps.MapTypeId.HYBRID, visibility: false});
				map.addLayer(ghyb);
				
				var base = 'google';
			}
			
			if (geoface.metadata.epsg != geoface.internalEpsg){
				
				var geojson_format = new OpenLayers.Format.GeoJSON({
				    'externalProjection': new OpenLayers.Projection('EPSG:' + geoface.metadata.epsg),
				    'internalProjection': internalProjection
				  });
			} else {
				var geojson_format = new OpenLayers.Format.GeoJSON();
			}
			
			// LOAD VECTOR LAYER
			if (typeof(base) != 'undefined'){
				var vector_layer = new OpenLayers.Layer.Vector(geoface.metadata.tb);
			} else {
				var vector_layer = new OpenLayers.Layer.Vector(geoface.metadata.tb, {isBaseLayer: true});
			}
			geoface.vector_layer = vector_layer;
			
			if (geoface.geoJSON){
				//add features to main layer
				vector_layer.addFeatures(geojson_format.read(geoface.geoJSON));
			}
			
			// add main layer to map
			map.addLayer(vector_layer);
			
			/**
			 * KML test
			
			var kml = new OpenLayers.Layer.Vector("Test KML", {
				strategies: [new OpenLayers.Strategy.Fixed()],
				projection: new OpenLayers.Projection('EPSG:4326'),
				protocol: new OpenLayers.Protocol.HTTP({
				                url: "./projects/msp/geodata/maiki.kml",
				                format: new OpenLayers.Format.KML({
				                    extractStyles: true, 
				                    extractAttributes: true,
				                    maxDepth: 2
				                })
				            })
				});
				map.addLayer(kml);
			*/
			if (geoface.geoJSON){
				//extend map to fit main layer content
				map.zoomToExtent(vector_layer.getDataExtent());
			} else {
				map.setCenter(new OpenLayers.LonLat(0, 0), 0);
			}
			

			
			// add user geojson layers
			var vect = [];
			if (geoface.otherLayers.length > 0){
				$.each(geoface.otherLayers, function(i, obj){
					var styleMap;
					if (typeof(obj.style) == 'object'){
						styleMap = new OpenLayers.StyleMap(obj.style);
					}
						
					vect[i] = new OpenLayers.Layer.Vector(obj.name, {styleMap: styleMap});
						
						if (obj.epsg != geoface.internalEpsg){
							vect[i].addFeatures(
								new OpenLayers.Format.GeoJSON({
								    'externalProjection': new OpenLayers.Projection('EPSG:' + obj.epsg),
								    'internalProjection': internalProjection
								  })
								.read(obj.data)
								);
						} else {
							vect[i].addFeatures( new OpenLayers.Format.GeoJSON().read(obj.data));
						}
						
						map.addLayer(vect[i]);
						
						vect[i].events.on({
							'featureselected'	: function(event){	geoface.onFeatureSelect(event); },
							'featureunselected'	: function(event){	geoface.onFeatureUnselect(event); }
						});
				});
			}
			
			
			map.setLayerIndex(vector_layer, map.layers.length);
			
			// CONTROLS
			vect.push(vector_layer);
			
			// editing control on vector_layer
			controls = {
		            point: new OpenLayers.Control.DrawFeature(vector_layer, OpenLayers.Handler.Point),
		            line: new OpenLayers.Control.DrawFeature(vector_layer, OpenLayers.Handler.Path),
		            polygon: new OpenLayers.Control.DrawFeature(vector_layer, OpenLayers.Handler.Polygon),
		            modify: new OpenLayers.Control.ModifyFeature(vector_layer),
		            select: new OpenLayers.Control.SelectFeature(vect, {
								clickout: true,
								toggle: false,
								multiple: false,
								hover: false
							})
			};
			
			for(var key in controls) {
				map.addControl(controls[key]);
			}
			
			vector_layer.events.on({
				'featureadded'			: function(event){	geoface.end(event);	},
				'afterfeaturemodified'	: function(event){	geoface.end(event);	},
				//'sketchcomplete'		: function(event){	geoface.end(event);	},
				'featureselected'		: function(event){	geoface.onFeatureSelect(event);		},
				'featureunselected'		: function(event){	geoface.onFeatureUnselect(event);	}
			});
		
			var dialogButtons = [
			                     {	text: core.tr('navigate'),	click: function(){ geoface.toggleControl(); }			},
			                     {	text: core.tr('identify'),	click: function(){ geoface.toggleControl('select'); }	}
			                     ];
			if (geoface.metadata.canUserEdit == true){
				dialogButtons.push({	text: core.tr('edit'),			click: function(){ geoface.toggleControl('modify');		}	});
				dialogButtons.push({	text: core.tr('draw_point'),	click: function(){ geoface.toggleControl('point');		}	});
				dialogButtons.push({	text: core.tr('draw_line'),		click: function(){ geoface.toggleControl('line');		}	});
				dialogButtons.push({	text: core.tr('draw_polygon'),	click: function(){ geoface.toggleControl('polygon');	}	});
				dialogButtons.push({	text: core.tr('erase'),			click: function(){ geoface.toggleControl('erase');		}	});
			}
			
			$('#map_bar').addClass('navbar navbar-default').html('<ul class="nav navbar-nav"></ul>');
			$.each(dialogButtons, function(i, obj){
				$('#map_bar ul').append(
						$('<li' + (obj.text == core.tr('navigate') ? ' class="active" ' : '') + '></li>')
							.html($('<a />').attr('href', 'javascript:void(0)').html(obj.text))
							.click(function(){
								$(this).addClass('active');
								$(this).siblings().removeClass('active');
								obj.click();
							})
						);
			});
			
			$('<ul />').addClass('nav navbar-nav pull-right').css('font-size', '1.2em').append(
					$('<li />').hide().html($('<a />').html('<i class="showOnEdit glyphicon glyphicon-edit"></i>').click(function(){
						geoface.manualCoordinates();
					})),
					$('<li />').hide().html($('<a />').html('<i class="showOnEdit glyphicon glyphicon-globe"></i>').click(function(){
						geoface.locationCoordinates();
					}))
					).appendTo($('#map_bar'));
			
			
			geoface.map = map;
			geoface.layer = vector_layer;
			
			core.open({
				html: div,
				title: core.tr('GeoFace'),
				unique: true,
				loaded: function(){
					
					geoface.resize();
					$(window).on('resize', function(){
						geoface.resize();
					});
				}
			});
		},
		
		resize: function(){
			$('#map_container').css({
				'width':  '100%',
				'height': ($(window).height()-70)
				});
			map.updateSize();
		}
		
};