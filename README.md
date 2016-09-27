# Toolbox

Installation: 
Paste the T.php file in the root of the Magento installation.
In index.php, add the function -require_once('T.php');-.

Make sure SqlFormatter is snuggly sitting in the lib/ folder. 

Thats it! Now you can use T:: functions from anywhere in the code. 
A simple example: 
T::dexit(Mage::getModel('catalog/product')->getCollection());
