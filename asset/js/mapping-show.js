$(document).ready( function() {

var mappingMap = $('#mapping-map');
var mappingData = mappingMap.data('mapping');
var markersData = mappingMap.data('markers');

// Initialise the map.
var map = L.map('mapping-map');
var mapDefaultCenter = [0, 0];
var mapDefaultZoom = 1;
if (mappingData) {
    if (mappingData['o-module-mapping:default_lat'] && mappingData['o-module-mapping:default_lng']) {
        mapDefaultCenter = [
            mappingData['o-module-mapping:default_lat'],
            mappingData['o-module-mapping:default_lng']
        ];
    }
    if (mappingData['o-module-mapping:default_zoom']) {
        mapDefaultZoom = mappingData['o-module-mapping:default_zoom'];
    }
}
map.setView(mapDefaultCenter, mapDefaultZoom);

var baseMaps = {
    'Streets': L.tileLayer.provider('OpenStreetMap.Mapnik'),
    'Grayscale': L.tileLayer.provider('OpenStreetMap.BlackAndWhite'),
    'Satellite': L.tileLayer.provider('Esri.WorldImagery'),
    'Terrain': L.tileLayer.provider('Esri.WorldShadedRelief')
};
var drawnItems = new L.FeatureGroup();
var layerControl = L.control.layers(baseMaps);

map.addLayer(baseMaps['Streets']);
map.addLayer(drawnItems);
map.addControl(layerControl);
map.addControl(L.control.fitBounds(drawnItems));

$.each(markersData, function(index, data) {
    var latLng = L.latLng(data['o-module-mapping:lat'], data['o-module-mapping:lng']);
    var marker = L.marker(latLng);
    var popupContent = $('.mapping-marker-popup-content[data-marker-id="' + data['o:id'] + '"]');
    if (popupContent.length > 0) {
        popupContent = popupContent.clone().show();
        marker.bindPopup(popupContent[0]);
    }
    drawnItems.addLayer(marker);
});

// Switching sections changes map dimensions, so make the necessary adjustments.
$('#mapping-section').on('o:section-opened', function(e) {
    map.invalidateSize();
});

});
